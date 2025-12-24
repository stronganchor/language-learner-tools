(function ($) {
    'use strict';

    const cfg = window.llToolsWordGridData || {};
    const ajaxUrl = cfg.ajaxUrl || '';
    const nonce = cfg.nonce || '';
    const i18n = cfg.i18n || {};
    const isLoggedIn = !!cfg.isLoggedIn;
    const state = Object.assign({
        wordset_id: 0,
        category_ids: [],
        starred_word_ids: [],
        star_mode: 'normal',
        fast_transitions: false
    }, cfg.state || {});

    const $grids = $('[data-ll-word-grid]');
    if (!$grids.length) { return; }

    let currentAudio = null;
    let currentAudioButton = null;

    let vizContext = null;
    let vizAnalyser = null;
    let vizAnalyserData = null;
    let vizTimeData = null;
    let vizAnalyserConnected = false;
    let vizRafId = null;
    let vizBars = [];
    let vizBarLevels = [];
    let vizButton = null;
    let vizAudio = null;
    let vizSource = null;

    function ensureVisualizerContext() {
        if (vizContext) { return vizContext; }
        const Ctor = window.AudioContext || window.webkitAudioContext;
        if (!Ctor) { return null; }
        try {
            vizContext = new Ctor();
        } catch (_) {
            vizContext = null;
        }
        return vizContext;
    }

    function ensureVisualizerAnalyser() {
        const ctx = ensureVisualizerContext();
        if (!ctx) { return null; }
        if (!vizAnalyser) {
            vizAnalyser = ctx.createAnalyser();
            vizAnalyser.fftSize = 256;
            vizAnalyser.smoothingTimeConstant = 0.65;
            vizAnalyserData = new Uint8Array(vizAnalyser.frequencyBinCount);
            vizTimeData = new Uint8Array(vizAnalyser.fftSize);
        }
        if (!vizAnalyserConnected) {
            try {
                vizAnalyser.connect(ctx.destination);
                vizAnalyserConnected = true;
            } catch (_) {
                return null;
            }
        }
        return vizAnalyser;
    }

    function setVisualizerBars(button) {
        if (!button) { return false; }
        const bars = button.querySelectorAll('.ll-study-recording-visualizer .bar');
        if (!bars.length) { return false; }
        vizBars = Array.from(bars);
        vizBarLevels = vizBars.map(() => 0);
        vizButton = button;
        return true;
    }

    function resetVisualizerBars() {
        if (!vizBars.length) { return; }
        vizBars.forEach(function (bar) {
            bar.style.setProperty('--level', '0');
        });
        vizBarLevels = vizBars.map(() => 0);
    }

    function stopVisualizer() {
        if (vizRafId) {
            cancelAnimationFrame(vizRafId);
            vizRafId = null;
        }
        if (vizSource) {
            try { vizSource.disconnect(); } catch (_) {}
            vizSource = null;
        }
        if (vizButton) {
            $(vizButton).removeClass('ll-study-recording-btn--js');
        }
        resetVisualizerBars();
        vizBars = [];
        vizBarLevels = [];
        vizButton = null;
        vizAudio = null;
    }

    function updateVisualizer() {
        if (!vizAnalyser || !vizBars.length || !vizAnalyserData || !vizTimeData) {
            vizRafId = null;
            return;
        }
        if (!vizAudio) {
            stopVisualizer();
            return;
        }
        if (vizAudio.paused) {
            if (vizAudio.currentTime === 0 && !vizAudio.ended) {
                vizRafId = requestAnimationFrame(updateVisualizer);
                return;
            }
            stopVisualizer();
            return;
        }
        if (!vizContext || vizContext.state !== 'running') {
            vizRafId = requestAnimationFrame(updateVisualizer);
            return;
        }

        vizAnalyser.getByteFrequencyData(vizAnalyserData);
        vizAnalyser.getByteTimeDomainData(vizTimeData);

        const slice = Math.max(1, Math.floor(vizAnalyserData.length / vizBars.length));
        let sumSquares = 0;
        for (let i = 0; i < vizTimeData.length; i++) {
            const deviation = vizTimeData[i] - 128;
            sumSquares += deviation * deviation;
        }
        const rms = Math.min(1, Math.sqrt(sumSquares / vizTimeData.length) / 64);

        for (let i = 0; i < vizBars.length; i++) {
            let sum = 0;
            for (let j = 0; j < slice; j++) {
                sum += vizAnalyserData[(i * slice) + j] || 0;
            }
            const avg = sum / slice;
            const normalized = Math.max(0, (avg - 40) / 215);
            const combined = Math.min(1, (normalized * 0.7) + (rms * 0.9));
            const eased = Math.pow(combined, 1.35);

            const previous = vizBarLevels[i] || 0;
            const level = previous + (eased - previous) * 0.35;
            vizBarLevels[i] = level;
            vizBars[i].style.setProperty('--level', level.toFixed(3));
        }

        vizRafId = requestAnimationFrame(updateVisualizer);
    }

    function startVisualizer(audio, button) {
        if (!audio || !button) { return; }
        stopVisualizer();
        const ctx = ensureVisualizerContext();
        if (!ctx) { return; }
        const resumePromise = (ctx.state === 'suspended') ? ctx.resume() : Promise.resolve();
        const targetAudio = audio;
        const targetButton = button;

        resumePromise.then(function () {
            if (targetAudio !== currentAudio || targetButton !== currentAudioButton) { return; }
            const analyser = ensureVisualizerAnalyser();
            if (!analyser) { return; }
            if (!setVisualizerBars(button)) { return; }

            let source = audio.__llWordGridVisualizerSource;
            if (!source) {
                try {
                    source = ctx.createMediaElementSource(audio);
                    audio.__llWordGridVisualizerSource = source;
                } catch (_) {
                    return;
                }
            }

            try { source.disconnect(); } catch (_) {}
            try {
                source.connect(analyser);
            } catch (_) {
                try { source.connect(ctx.destination); } catch (_) {}
                return;
            }

            vizSource = source;
            vizAudio = audio;
            vizButton = button;
            $(button).addClass('ll-study-recording-btn--js');

            if (vizRafId) {
                cancelAnimationFrame(vizRafId);
            }
            updateVisualizer();
        }).catch(function () {});
    }

    function stopCurrentAudio() {
        if (currentAudio) {
            currentAudio.pause();
            currentAudio.currentTime = 0;
        }
        if (currentAudioButton) {
            $(currentAudioButton).removeClass('is-playing');
        }
        stopVisualizer();
        currentAudio = null;
        currentAudioButton = null;
    }

    $grids.on('click', '.ll-study-recording-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const $btn = $(this);
        const url = $btn.attr('data-audio-url') || '';
        if (!url) { return; }

        if (currentAudio && currentAudioButton === this) {
            if (!currentAudio.paused) {
                currentAudio.pause();
                $btn.removeClass('is-playing');
                return;
            }
            startVisualizer(currentAudio, this);
            currentAudio.play().catch(function () {
                stopVisualizer();
            });
            return;
        }

        stopCurrentAudio();
        currentAudio = new Audio(url);
        currentAudioButton = this;
        currentAudio.addEventListener('play', function () {
            if (currentAudio !== this) { return; }
            $btn.addClass('is-playing');
        });
        currentAudio.addEventListener('pause', function () {
            if (currentAudio !== this) { return; }
            $btn.removeClass('is-playing');
            stopVisualizer();
        });
        currentAudio.addEventListener('ended', function () {
            if (currentAudio !== this) { return; }
            $btn.removeClass('is-playing');
            stopVisualizer();
            currentAudio = null;
            currentAudioButton = null;
        });
        currentAudio.addEventListener('error', function () {
            if (currentAudio !== this) { return; }
            $btn.removeClass('is-playing');
            stopVisualizer();
        });
        startVisualizer(currentAudio, this);
        if (currentAudio.play) {
            currentAudio.play().catch(function () {
                stopVisualizer();
            });
        }
    });

    if (!isLoggedIn) { return; }

    let starredIds = normalizeIds(state.starred_word_ids);
    let saveTimer = null;
    let internalStarChange = false;

    function normalizeIds(arr) {
        return (arr || []).map(function (v) { return parseInt(v, 10) || 0; })
            .filter(function (v) { return v > 0; })
            .filter(function (v, idx, list) { return list.indexOf(v) === idx; });
    }

    function isStarred(wordId) {
        return starredIds.indexOf(wordId) !== -1;
    }

    function setStarredIds(ids) {
        starredIds = normalizeIds(ids);
        state.starred_word_ids = starredIds.slice();
    }

    function updateStarButton($btn, shouldStar) {
        $btn.toggleClass('active', shouldStar);
        $btn.attr('aria-pressed', shouldStar ? 'true' : 'false');
        const label = shouldStar ? (i18n.unstarLabel || '') : (i18n.starLabel || '');
        if (label) {
            $btn.attr('aria-label', label);
            $btn.attr('title', label);
        }
    }

    function updateStarButtons(wordId, shouldStar) {
        $grids.find('.ll-word-grid-star[data-word-id="' + wordId + '"]').each(function () {
            updateStarButton($(this), shouldStar);
        });
    }

    function setStudyPrefsGlobal() {
        if (!window.llToolsFlashcardsData) { return; }
        const synced = starredIds.slice();
        window.llToolsFlashcardsData.starredWordIds = synced;
        window.llToolsFlashcardsData.starred_word_ids = synced;
        if (window.llToolsFlashcardsData.userStudyState) {
            window.llToolsFlashcardsData.userStudyState.starred_word_ids = synced;
        }
    }

    function saveStateDebounced() {
        if (!ajaxUrl || !nonce) { return; }
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function () {
            $.post(ajaxUrl, {
                action: 'll_user_study_save',
                nonce: nonce,
                wordset_id: state.wordset_id,
                category_ids: state.category_ids,
                starred_word_ids: state.starred_word_ids,
                star_mode: state.star_mode,
                fast_transitions: state.fast_transitions ? 1 : 0
            });
        }, 300);
    }

    $grids.on('click', '.ll-word-grid-star', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const $btn = $(this);
        const wordId = parseInt($btn.attr('data-word-id'), 10) || 0;
        if (!wordId) { return; }

        const shouldStar = !isStarred(wordId);
        if (shouldStar) {
            starredIds.push(wordId);
        } else {
            starredIds = starredIds.filter(function (id) { return id !== wordId; });
        }
        setStarredIds(starredIds);
        updateStarButtons(wordId, shouldStar);
        setStudyPrefsGlobal();
        saveStateDebounced();

        internalStarChange = true;
        try {
            $(document).trigger('lltools:star-changed', [{ wordId: wordId, starred: shouldStar }]);
        } finally {
            internalStarChange = false;
        }
    });

    $(document).on('lltools:star-changed', function (_evt, detail) {
        if (internalStarChange) { return; }
        const info = detail || {};
        const wordId = parseInt(info.wordId || info.word_id, 10) || 0;
        if (!wordId) { return; }
        const shouldStar = info.starred !== false;

        if (shouldStar && !isStarred(wordId)) {
            starredIds.push(wordId);
        } else if (!shouldStar && isStarred(wordId)) {
            starredIds = starredIds.filter(function (id) { return id !== wordId; });
        } else {
            return;
        }

        setStarredIds(starredIds);
        updateStarButtons(wordId, shouldStar);
        setStudyPrefsGlobal();
        saveStateDebounced();
    });
})(jQuery);
