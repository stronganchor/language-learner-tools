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
    const $starToggles = $('[data-ll-word-grid-star-toggle]');
    const $starModeButtons = $('.ll-vocab-lesson-star-mode');
    const $lessonSettings = $('.ll-vocab-lesson-settings');

    function getStudySettings() {
        return (window.LLFlashcards && window.LLFlashcards.StudySettings)
            ? window.LLFlashcards.StudySettings
            : null;
    }

    function normalizeIds(arr) {
        return (arr || []).map(function (v) { return parseInt(v, 10) || 0; })
            .filter(function (v) { return v > 0; })
            .filter(function (v, idx, list) { return list.indexOf(v) === idx; });
    }

    function normalizeStarMode(mode) {
        const studySettings = getStudySettings();
        if (studySettings && typeof studySettings.normalizeStarMode === 'function') {
            return studySettings.normalizeStarMode(mode);
        }
        const normalized = String(mode || '').toLowerCase();
        if (normalized === 'weighted' || normalized === 'only' || normalized === 'normal') {
            return normalized;
        }
        return 'normal';
    }

    function setStarModeLocal(mode) {
        const normalized = normalizeStarMode(mode);
        state.star_mode = normalized;
        state.starMode = normalized;
        return normalized;
    }

    let currentStarMode = normalizeStarMode(
        (getStudySettings() && typeof getStudySettings().getStarMode === 'function')
            ? getStudySettings().getStarMode()
            : (state.star_mode ||
                state.starMode ||
                (window.llToolsFlashcardsData && (window.llToolsFlashcardsData.starMode || window.llToolsFlashcardsData.star_mode)) ||
                'normal')
    );
    setStarModeLocal(currentStarMode);

    function isStarred(wordId) {
        return starredIds.indexOf(wordId) !== -1;
    }

    function setStarredIds(ids) {
        starredIds = normalizeIds(ids);
        state.starred_word_ids = starredIds.slice();
    }

    function setStarMode(mode) {
        const normalized = setStarModeLocal(mode);

        if (window.llToolsStudyPrefs) {
            window.llToolsStudyPrefs.starMode = normalized;
            window.llToolsStudyPrefs.star_mode = normalized;
        }

        if (window.llToolsFlashcardsData) {
            window.llToolsFlashcardsData.starMode = normalized;
            window.llToolsFlashcardsData.star_mode = normalized;
            if (window.llToolsFlashcardsData.userStudyState) {
                window.llToolsFlashcardsData.userStudyState.star_mode = normalized;
            }
        }

        return normalized;
    }

    function getGridWordIds($grid) {
        const ids = [];
        $grid.find('.ll-word-grid-star[data-word-id]').each(function () {
            const wordId = parseInt($(this).attr('data-word-id'), 10) || 0;
            if (wordId) {
                ids.push(wordId);
            }
        });
        return normalizeIds(ids);
    }

    function getGridForToggle($toggle) {
        let $grid = $();
        const $scope = $toggle.closest('[data-ll-vocab-lesson],.ll-vocab-lesson-page');
        if ($scope.length) {
            $grid = $scope.find('[data-ll-word-grid]').first();
        }
        if (!$grid.length) {
            $grid = $grids.first();
        }
        return $grid;
    }

    function updateStarToggle($toggle) {
        const $grid = getGridForToggle($toggle);
        const wordIds = $grid.length ? getGridWordIds($grid) : [];
        const hasWords = wordIds.length > 0;
        const allStarred = hasWords && wordIds.every(function (wordId) {
            return isStarred(wordId);
        });
        const label = allStarred ? (i18n.unstarAllLabel || '') : (i18n.starAllLabel || '');
        const iconChar = allStarred ? '\u2605' : '\u2606';
        const $icon = $toggle.find('.ll-vocab-lesson-star-icon');
        const $label = $toggle.find('.ll-vocab-lesson-star-label');
        if ($icon.length) {
            $icon.text(iconChar);
        }
        if (label) {
            if ($label.length) {
                $label.text(label);
            } else {
                $toggle.text(iconChar + ' ' + label);
            }
        }
        $toggle.toggleClass('active', allStarred);
        $toggle.attr('aria-pressed', allStarred ? 'true' : 'false');
        $toggle.prop('disabled', !hasWords);
    }

    function updateAllStarToggles() {
        if (!$starToggles.length) { return; }
        $starToggles.each(function () {
            updateStarToggle($(this));
        });
    }

    function canUseStarOnlyForGrid($grid) {
        const wordIds = $grid.length ? getGridWordIds($grid) : [];
        if (!wordIds.length) {
            return false;
        }
        return wordIds.some(function (wordId) {
            return isStarred(wordId);
        });
    }

    function updateStarModeButtons() {
        if (!$starModeButtons.length) { return; }
        const studySettings = getStudySettings();
        if (studySettings && typeof studySettings.syncStarModeButtons === 'function') {
            studySettings.syncStarModeButtons($starModeButtons, {
                canUseStarOnly: function (button) {
                    const $grid = getGridForToggle($(button));
                    return canUseStarOnlyForGrid($grid);
                }
            });
            if (typeof studySettings.getStarMode === 'function') {
                setStarModeLocal(studySettings.getStarMode());
            }
            return;
        }
        const currentMode = normalizeStarMode(state.star_mode || 'normal');

        $starModeButtons.each(function () {
            const $btn = $(this);
            const mode = normalizeStarMode($btn.data('star-mode') || '');
            if (!mode) { return; }
            const $grid = getGridForToggle($btn);
            const allowOnly = canUseStarOnlyForGrid($grid);
            const shouldDisable = (mode === 'only') && !allowOnly;
            const isActive = mode === currentMode;

            $btn.toggleClass('active', isActive).attr('aria-pressed', isActive ? 'true' : 'false');
            $btn.prop('disabled', shouldDisable).attr('aria-disabled', shouldDisable ? 'true' : 'false');
        });
    }

    function applyStarModeSelection(mode) {
        const normalized = normalizeStarMode(mode);
        if (!normalized) { return false; }
        const studySettings = getStudySettings();
        if (studySettings && typeof studySettings.applyStarMode === 'function') {
            studySettings.applyStarMode(normalized);
            if (typeof studySettings.getStarMode === 'function') {
                setStarModeLocal(studySettings.getStarMode());
            } else {
                setStarModeLocal(normalized);
            }
            return true;
        }
        setStarMode(normalized);
        saveStateDebounced();
        return false;
    }

    function setLessonSettingsOpen($wrap, shouldOpen) {
        if (!$wrap || !$wrap.length) { return; }
        const $panel = $wrap.find('.ll-vocab-lesson-settings-panel');
        const $button = $wrap.find('.ll-vocab-lesson-settings-button');
        if (!$panel.length || !$button.length) { return; }
        const open = !!shouldOpen;
        $panel.attr('aria-hidden', open ? 'false' : 'true');
        $button.attr('aria-expanded', open ? 'true' : 'false');
        $wrap.toggleClass('is-open', open);
        if (open) {
            updateStarModeButtons();
        }
    }

    function closeLessonSettings(except) {
        if (!$lessonSettings.length) { return; }
        $lessonSettings.each(function () {
            const $wrap = $(this);
            if (except && $wrap.is(except)) { return; }
            setLessonSettingsOpen($wrap, false);
        });
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
        if (window.llToolsStudyPrefs) {
            window.llToolsStudyPrefs.starredWordIds = synced;
            window.llToolsStudyPrefs.starred_word_ids = synced;
        }
        if (window.llToolsStudyData && window.llToolsStudyData.payload && window.llToolsStudyData.payload.state) {
            window.llToolsStudyData.payload.state.starred_word_ids = synced;
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

    if ($starToggles.length) {
        $starToggles.on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $toggle = $(this);
            if ($toggle.prop('disabled')) { return; }
            const $grid = getGridForToggle($toggle);
            if (!$grid.length) { return; }
            const wordIds = getGridWordIds($grid);
            if (!wordIds.length) {
                updateStarToggle($toggle);
                return;
            }

            const current = new Set(starredIds);
            const allStarred = wordIds.every(function (wordId) {
                return current.has(wordId);
            });
            const shouldStar = !allStarred;
            const changed = [];

            wordIds.forEach(function (wordId) {
                const hasStar = current.has(wordId);
                if (shouldStar && !hasStar) {
                    current.add(wordId);
                    changed.push({ wordId: wordId, starred: true });
                } else if (!shouldStar && hasStar) {
                    current.delete(wordId);
                    changed.push({ wordId: wordId, starred: false });
                }
            });

            if (!changed.length) {
                updateStarToggle($toggle);
                return;
            }

            setStarredIds(Array.from(current));
            changed.forEach(function (entry) {
                updateStarButtons(entry.wordId, entry.starred);
            });
            setStudyPrefsGlobal();
            saveStateDebounced();

            internalStarChange = true;
            try {
                changed.forEach(function (entry) {
                    $(document).trigger('lltools:star-changed', [entry]);
                });
            } finally {
                internalStarChange = false;
            }

            updateAllStarToggles();
            updateStarModeButtons();
        });
    }

    if ($lessonSettings.length) {
        $lessonSettings.on('click', '.ll-vocab-lesson-settings-button', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $wrap = $(this).closest('.ll-vocab-lesson-settings');
            const $panel = $wrap.find('.ll-vocab-lesson-settings-panel');
            const isOpen = $panel.attr('aria-hidden') === 'false';
            closeLessonSettings($wrap);
            setLessonSettingsOpen($wrap, !isOpen);
        });

        $lessonSettings.on('click', '.ll-vocab-lesson-settings-panel', function (e) {
            e.stopPropagation();
        });

        $(document).on('pointerdown.llLessonSettings', function (e) {
            if ($(e.target).closest('.ll-vocab-lesson-settings').length) { return; }
            closeLessonSettings();
        });

        $(document).on('keydown.llLessonSettings', function (e) {
            if (e.key === 'Escape') {
                closeLessonSettings();
            }
        });
    }

    if ($starModeButtons.length) {
        $starModeButtons.on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $btn = $(this);
            if ($btn.prop('disabled')) { return; }
            const mode = normalizeStarMode($btn.data('star-mode') || '');
            if (!mode) { return; }
            applyStarModeSelection(mode);
            updateStarModeButtons();
        });
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
        updateAllStarToggles();
        updateStarModeButtons();

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
        updateAllStarToggles();
        updateStarModeButtons();
    });

    updateAllStarToggles();
    updateStarModeButtons();
})(jQuery);
