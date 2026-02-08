(function ($) {
    'use strict';

    const cfg = window.llToolsWordGridData || {};
    const ajaxUrl = cfg.ajaxUrl || '';
    const nonce = cfg.nonce || '';
    const i18n = cfg.i18n || {};
    const editI18n = cfg.editI18n || {};
    const bulkI18n = cfg.bulkI18n || {};
    const transcribeI18n = cfg.transcribeI18n || {};
    const transcribePollAttemptsRaw = parseInt(cfg.transcribePollAttempts, 10);
    const transcribePollIntervalRaw = parseInt(cfg.transcribePollIntervalMs, 10);
    const transcribePollAttempts = Number.isFinite(transcribePollAttemptsRaw) && transcribePollAttemptsRaw > 0
        ? transcribePollAttemptsRaw
        : 20;
    const transcribePollIntervalMs = Number.isFinite(transcribePollIntervalRaw) && transcribePollIntervalRaw >= 250
        ? transcribePollIntervalRaw
        : 1200;
    const ipaSpecialChars = Array.isArray(cfg.ipaSpecialChars) ? cfg.ipaSpecialChars.slice() : [];
    const ipaLetterMap = (cfg.ipaLetterMap && typeof cfg.ipaLetterMap === 'object') ? cfg.ipaLetterMap : {};
    const ipaCommonChars = ['t͡ʃ', 'd͡ʒ', 'ʃ', 'ˈ'];
    const isLoggedIn = !!cfg.isLoggedIn;
    const canEdit = !!cfg.canEdit;
    const editNonce = cfg.editNonce || '';
    const supportsIpaExtended = cfg.supportsIpaExtended !== false;
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

    function canUseVisualizerForUrl(url) {
        if (!url) { return false; }
        const value = String(url);
        if (value.indexOf('blob:') === 0 || value.indexOf('data:') === 0) {
            return true;
        }
        try {
            const target = new URL(value, window.location.href);
            return target.origin === window.location.origin;
        } catch (_) {
            return false;
        }
    }

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
        const src = audio.currentSrc || audio.src || '';
        if (!canUseVisualizerForUrl(src)) { return; }
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

    let titleWrapTimer = null;

    const measureCanvas = document.createElement('canvas');
    const measureCtx = measureCanvas.getContext('2d');

    function parseGapValue(value) {
        if (!value) { return 0; }
        const parts = value.toString().split(' ');
        const parsed = parseFloat(parts[0]);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function getFontString(el) {
        if (!el) { return ''; }
        const style = window.getComputedStyle(el);
        if (style.font && style.font !== '') {
            return style.font;
        }
        return [
            style.fontStyle || 'normal',
            style.fontVariant || 'normal',
            style.fontWeight || 'normal',
            style.fontSize || '16px',
            style.fontFamily || 'sans-serif'
        ].join(' ');
    }

    function measureTextWidth(text, el) {
        if (!measureCtx || !text) { return 0; }
        const font = getFontString(el);
        if (font) {
            measureCtx.font = font;
        }
        return measureCtx.measureText(text).width || 0;
    }

    function updateTitleWraps() {
        $grids.find('.word-item').each(function () {
            const $item = $(this);
            const item = $item.get(0);
            const rowEl = $item.find('.ll-word-title-row').get(0);
            const titleEl = $item.find('.word-title').get(0);
            const textEl = $item.find('[data-ll-word-text]').get(0);
            const translationEl = $item.find('[data-ll-word-translation]').get(0);
            if (!item || !rowEl || !titleEl || !textEl || !translationEl) {
                $item.removeClass('ll-word-title--stack');
                if (item) {
                    item.style.removeProperty('--ll-word-title-width');
                }
                return;
            }
            const translationText = (translationEl.textContent || '').trim();
            if (!translationText) {
                $item.removeClass('ll-word-title--stack');
                item.style.removeProperty('--ll-word-title-width');
                return;
            }

            $item.removeClass('ll-word-title--stack');
            item.style.removeProperty('--ll-word-title-width');

            const itemStyle = window.getComputedStyle(item);
            const itemWidth = item.getBoundingClientRect().width;
            const paddingX = parseGapValue(itemStyle.paddingLeft) + parseGapValue(itemStyle.paddingRight);
            const contentWidth = Math.max(0, itemWidth - paddingX);

            const rowStyle = window.getComputedStyle(rowEl);
            const rowGap = parseGapValue(rowStyle.columnGap || rowStyle.gap || '0');
            const starEl = rowEl.querySelector('.ll-word-star');
            const editEl = rowEl.querySelector('.ll-word-edit-toggle');
            const starWidth = starEl ? starEl.getBoundingClientRect().width : 0;
            const editWidth = editEl ? editEl.getBoundingClientRect().width : 0;
            let gapCount = 0;
            if (starEl && editEl) {
                gapCount = 2;
            } else if (starEl || editEl) {
                gapCount = 1;
            }
            const availableWidth = Math.max(0, contentWidth - starWidth - editWidth - (rowGap * gapCount));
            if (availableWidth <= 0) {
                return;
            }

            const titleStyle = window.getComputedStyle(titleEl);
            const titleGap = parseGapValue(titleStyle.columnGap || titleStyle.gap || '0');
            const rawText = (textEl.textContent || '').trim();
            const rawTranslation = translationText;
            const measuredTextWidth = measureTextWidth(rawText, textEl);
            const measuredTranslationWidth = measureTextWidth(rawTranslation ? ('(' + rawTranslation + ')') : '', translationEl);
            const combinedWidth = measuredTextWidth + measuredTranslationWidth + (rawTranslation ? titleGap : 0);
            const cushion = 2;

            if (combinedWidth <= availableWidth) {
                $item.removeClass('ll-word-title--stack');
                item.style.setProperty('--ll-word-title-width', (combinedWidth + cushion) + 'px');
            } else {
                $item.addClass('ll-word-title--stack');
                const widestLine = Math.max(measuredTextWidth, measuredTranslationWidth);
                const targetWidth = Math.min(availableWidth, widestLine);
                item.style.setProperty('--ll-word-title-width', (targetWidth + cushion) + 'px');
            }
        });
    }

    function updateRecordingRowWidths() {
        $grids.find('.word-item').each(function () {
            const item = this;
            if (!item) { return; }
            const itemRect = item.getBoundingClientRect();
            if (!itemRect.width) { return; }
            const itemStyle = window.getComputedStyle(item);
            const paddingX = parseGapValue(itemStyle.paddingLeft) + parseGapValue(itemStyle.paddingRight);
            const contentWidth = Math.max(0, itemRect.width - paddingX);

            $(item).find('.ll-word-recording-row').each(function () {
                const row = this;
                const textWrap = row.querySelector('.ll-word-recording-text');
                if (!textWrap) {
                    row.style.removeProperty('width');
                    return;
                }
                const mainEl = textWrap.querySelector('.ll-word-recording-text-main');
                const translationEl = textWrap.querySelector('.ll-word-recording-text-translation');
                const ipaEl = textWrap.querySelector('.ll-word-recording-ipa');
                const mainText = mainEl ? (mainEl.textContent || '').trim() : '';
                const translationText = translationEl ? (translationEl.textContent || '').trim() : '';
                const ipaText = ipaEl ? (ipaEl.textContent || '').trim() : '';

                if (!mainText && !translationText && !ipaText) {
                    row.style.removeProperty('width');
                    return;
                }

                const rowStyle = window.getComputedStyle(row);
                const rowGap = parseGapValue(rowStyle.columnGap || rowStyle.gap || '0');
                const textStyle = window.getComputedStyle(textWrap);
                const textGap = parseGapValue(textStyle.columnGap || textStyle.gap || '0');
                const btnEl = row.querySelector('.ll-study-recording-btn');
                const btnWidth = btnEl ? btnEl.getBoundingClientRect().width : 0;
                const availableTextWidth = Math.max(0, contentWidth - btnWidth - rowGap);

                let mainWidth = 0;
                if (mainText) {
                    mainWidth = measureTextWidth(mainText, mainEl || textWrap);
                }
                let translationWidth = 0;
                if (translationText) {
                    translationWidth = measureTextWidth('(' + translationText + ')', translationEl || textWrap);
                }
                let ipaWidth = 0;
                if (ipaText) {
                    ipaWidth = measureTextWidth('[' + ipaText + ']', ipaEl || textWrap);
                }

                const hasBoth = mainWidth > 0 && translationWidth > 0;
                const combinedWidth = hasBoth ? (mainWidth + translationWidth + textGap) : Math.max(mainWidth, translationWidth);
                let lineWidth = combinedWidth;
                if (combinedWidth > availableTextWidth) {
                    lineWidth = Math.max(mainWidth, translationWidth);
                }
                if (ipaWidth > lineWidth) {
                    lineWidth = ipaWidth;
                }
                if (availableTextWidth > 0) {
                    lineWidth = Math.min(lineWidth, availableTextWidth);
                }

                let targetWidth = btnWidth + rowGap + lineWidth;
                if (contentWidth > 0) {
                    targetWidth = Math.min(contentWidth, targetWidth);
                }

                if (targetWidth > 0) {
                    row.style.width = targetWidth.toFixed(2) + 'px';
                } else {
                    row.style.removeProperty('width');
                }
            });
        });
    }

    function updateGridLayouts() {
        updateTitleWraps();
        updateRecordingRowWidths();
    }

    function scheduleTitleWrapUpdate() {
        clearTimeout(titleWrapTimer);
        titleWrapTimer = setTimeout(updateGridLayouts, 50);
    }

    updateGridLayouts();
    $(window).on('resize.llWordTitleWrap', scheduleTitleWrapUpdate);

    if (!isLoggedIn) { return; }

    let starredIds = normalizeIds(state.starred_word_ids);
    let saveTimer = null;
    let internalStarChange = false;
    const $starToggles = $('[data-ll-word-grid-star-toggle]');
    const $starModeButtons = $('.ll-vocab-lesson-star-mode');
    const $lessonSettings = $('.ll-vocab-lesson-settings');
    const $bulkEditors = $('[data-ll-word-grid-bulk]');

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

    function setBulkEditorOpen($wrap, shouldOpen) {
        if (!$wrap || !$wrap.length) { return; }
        const $panel = $wrap.find('.ll-vocab-lesson-bulk-panel');
        const $button = $wrap.find('.ll-vocab-lesson-bulk-button');
        if (!$panel.length || !$button.length) { return; }
        const open = !!shouldOpen;
        $panel.attr('aria-hidden', open ? 'false' : 'true');
        $button.attr('aria-expanded', open ? 'true' : 'false');
        $wrap.toggleClass('is-open', open);
    }

    function closeBulkEditors(except) {
        if (!$bulkEditors.length) { return; }
        $bulkEditors.each(function () {
            const $wrap = $(this);
            if (except && $wrap.is(except)) { return; }
            setBulkEditorOpen($wrap, false);
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

    if ($bulkEditors.length) {
        $bulkEditors.on('click', '.ll-vocab-lesson-bulk-button', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $wrap = $(this).closest('[data-ll-word-grid-bulk]');
            const $panel = $wrap.find('.ll-vocab-lesson-bulk-panel');
            const isOpen = $panel.attr('aria-hidden') === 'false';
            closeBulkEditors($wrap);
            setBulkEditorOpen($wrap, !isOpen);
        });

        $bulkEditors.on('click', '.ll-vocab-lesson-bulk-panel', function (e) {
            e.stopPropagation();
        });

        $(document).on('pointerdown.llLessonBulk', function (e) {
            if ($(e.target).closest('[data-ll-word-grid-bulk]').length) { return; }
            closeBulkEditors();
        });

        $(document).on('keydown.llLessonBulk', function (e) {
            if (e.key === 'Escape') {
                closeBulkEditors();
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

    const editMessages = {
        saving: editI18n.saving || 'Saving...',
        saved: editI18n.saved || 'Saved.',
        error: editI18n.error || 'Unable to save changes.'
    };
    const bulkMessages = {
        saving: bulkI18n.saving || 'Updating...',
        posSuccess: bulkI18n.posSuccess || 'Updated %d words.',
        genderSuccess: bulkI18n.genderSuccess || 'Updated %d nouns.',
        pluralitySuccess: bulkI18n.pluralitySuccess || 'Updated %d nouns.',
        posMissing: bulkI18n.posMissing || 'Choose a part of speech.',
        genderMissing: bulkI18n.genderMissing || 'Choose a gender.',
        pluralityMissing: bulkI18n.pluralityMissing || 'Choose a plurality option.',
        error: bulkI18n.error || 'Unable to update words.'
    };

    function setEditPanelOpen($item, shouldOpen) {
        const $panel = $item.find('[data-ll-word-edit-panel]').first();
        const $toggle = $item.find('[data-ll-word-edit-toggle]').first();
        if (!$panel.length || !$toggle.length) { return; }
        const open = !!shouldOpen;
        $panel.attr('aria-hidden', open ? 'false' : 'true');
        $toggle.attr('aria-expanded', open ? 'true' : 'false');
        $item.toggleClass('ll-word-edit-open', open);
        if (open) {
            const scrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
            $item.data('llEditScrollY', scrollY);
        } else {
            const scrollY = $item.data('llEditScrollY');
            if (typeof scrollY === 'number') {
                $item.removeData('llEditScrollY');
                window.requestAnimationFrame(function () {
                    window.scrollTo(0, scrollY);
                });
            }
        }
    }

    function setRecordingsPanelOpen($item, shouldOpen) {
        const $panel = $item.find('[data-ll-word-recordings-panel]').first();
        const $toggle = $item.find('[data-ll-word-recordings-toggle]').first();
        if (!$panel.length || !$toggle.length) { return; }
        const open = !!shouldOpen;
        $panel.attr('aria-hidden', open ? 'false' : 'true');
        $toggle.attr('aria-expanded', open ? 'true' : 'false');
        $item.toggleClass('ll-word-recordings-open', open);
    }

    function cacheOriginalInputs($item) {
        $item.find('input[data-ll-word-input], select[data-ll-word-input], input[data-ll-recording-input], select[data-ll-recording-input]').each(function () {
            const $input = $(this);
            $input.data('original', $input.val() || '');
        });
    }

    function restoreOriginalInputs($item) {
        $item.find('input[data-ll-word-input], select[data-ll-word-input], input[data-ll-recording-input], select[data-ll-recording-input]').each(function () {
            const $input = $(this);
            const original = $input.data('original');
            if (typeof original === 'string') {
                $input.val(original);
            }
        });
        setMetaFieldState($item);
    }

    function setEditStatus($item, message, isError) {
        const $status = $item.find('[data-ll-word-edit-status]').first();
        if (!$status.length) { return; }
        $status.text(message || '');
        $status.toggleClass('is-error', !!isError);
    }

    function formatBulkMessage(template, count) {
        const safe = template || '';
        return safe.replace('%1$d', String(count)).replace('%d', String(count));
    }

    function setBulkStatus($wrap, message, isError) {
        const $status = $wrap.find('[data-ll-bulk-status]').first();
        if (!$status.length) { return; }
        $status.text(message || '');
        $status.toggleClass('is-error', !!isError);
    }

    function setWordMetaText($item, selector, value) {
        const $el = $item.find(selector).first();
        if (!$el.length) { return; }
        $el.text(value || '');
    }

    function updateWordMetaRow($item) {
        const $row = $item.find('[data-ll-word-meta]').first();
        if (!$row.length) { return; }
        const posText = ($row.find('[data-ll-word-pos]').text() || '').trim();
        const genderText = ($row.find('[data-ll-word-gender]').text() || '').trim();
        const pluralityText = ($row.find('[data-ll-word-plurality]').text() || '').trim();
        $row.toggleClass('ll-word-meta-row--empty', !(posText || genderText || pluralityText));
    }

    function setMetaFieldState($item, posSlug) {
        const resolved = (posSlug || ($item.find('[data-ll-word-input="part_of_speech"]').val() || '')).toString();
        const isNoun = resolved === 'noun';
        const $genderField = $item.find('[data-ll-word-gender-field]').first();
        if ($genderField.length) {
            $genderField.toggleClass('ll-word-edit-gender--hidden', !isNoun);
            $genderField.attr('aria-hidden', isNoun ? 'false' : 'true');
            const $select = $genderField.find('select[data-ll-word-input="gender"]').first();
            if ($select.length) {
                $select.prop('disabled', !isNoun);
            }
        }
        const $pluralityField = $item.find('[data-ll-word-plurality-field]').first();
        if ($pluralityField.length) {
            $pluralityField.toggleClass('ll-word-edit-plurality--hidden', !isNoun);
            $pluralityField.attr('aria-hidden', isNoun ? 'false' : 'true');
            const $select = $pluralityField.find('select[data-ll-word-input="plurality"]').first();
            if ($select.length) {
                $select.prop('disabled', !isNoun);
            }
        }
    }

    function applyPosMetaUpdate($item, posData, genderData, pluralityData) {
        if (posData && Object.prototype.hasOwnProperty.call(posData, 'slug')) {
            $item.find('[data-ll-word-input="part_of_speech"]').val(posData.slug || '');
        }
        if (posData && Object.prototype.hasOwnProperty.call(posData, 'label')) {
            setWordMetaText($item, '[data-ll-word-pos]', posData.label || '');
        }
        if (genderData && Object.prototype.hasOwnProperty.call(genderData, 'value')) {
            $item.find('[data-ll-word-input="gender"]').val(genderData.value || '');
        }
        if (genderData && Object.prototype.hasOwnProperty.call(genderData, 'label')) {
            setWordMetaText($item, '[data-ll-word-gender]', genderData.label || '');
        }
        if (pluralityData && Object.prototype.hasOwnProperty.call(pluralityData, 'value')) {
            $item.find('[data-ll-word-input="plurality"]').val(pluralityData.value || '');
        }
        if (pluralityData && Object.prototype.hasOwnProperty.call(pluralityData, 'label')) {
            setWordMetaText($item, '[data-ll-word-plurality]', pluralityData.label || '');
        }
        updateWordMetaRow($item);
        if (posData && Object.prototype.hasOwnProperty.call(posData, 'slug')) {
            setMetaFieldState($item, posData.slug || '');
        } else {
            setMetaFieldState($item);
        }
    }

    function updateOriginalInputs($item) {
        $item.find('input[data-ll-word-input], select[data-ll-word-input], input[data-ll-recording-input], select[data-ll-recording-input]').each(function () {
            const $input = $(this);
            $input.data('original', $input.val() || '');
        });
    }

    function collectRecordingInputs($item) {
        const recordings = [];
        $item.find('.ll-word-edit-recording[data-recording-id]').each(function () {
            const $rec = $(this);
            const recId = parseInt($rec.attr('data-recording-id'), 10) || 0;
            if (!recId) { return; }
            const text = ($rec.find('[data-ll-recording-input="text"]').val() || '').toString();
            const translation = ($rec.find('[data-ll-recording-input="translation"]').val() || '').toString();
            const ipa = normalizeIpaForStorage(($rec.find('[data-ll-recording-input="ipa"]').val() || '').toString());
            recordings.push({
                id: recId,
                recording_text: text,
                recording_translation: translation,
                recording_ipa: ipa
            });
        });
        return recordings;
    }

    function setTranscribeStatus($wrap, message, isError) {
        const $status = $wrap.find('[data-ll-transcribe-status]').first();
        if (!$status.length) { return; }
        $status.text(message || '');
        $status.toggleClass('is-error', !!isError);
    }

    function formatTranscribeProgress(template, current, total) {
        return (template || '').replace('%1$d', String(current)).replace('%2$d', String(total));
    }

    function getRecordingCaptionParts(text, translation, ipa) {
        const cleanText = (text || '').toString().trim();
        const cleanTranslation = (translation || '').toString().trim();
        const cleanIpa = (ipa || '').toString().trim();
        return {
            text: cleanText,
            translation: cleanTranslation,
            ipa: cleanIpa,
            hasCaption: !!(cleanText || cleanTranslation || cleanIpa)
        };
    }

    function normalizeIpaOutput(value) {
        return (value || '').toString().replace(/\u1D2E/g, '\u{10784}');
    }

    function normalizeIpaForStorage(value) {
        const raw = (value || '').toString();
        if (supportsIpaExtended) {
            return raw;
        }
        return raw.replace(/\u{10784}/gu, '\u1D2E');
    }

    function renderRecordingCaption($row, parts) {
        if (!$row || !parts) { return; }
        let $textWrap = $row.find('.ll-word-recording-text').first();

        if (!parts.hasCaption) {
            $textWrap.remove();
            return;
        }

        if (!$textWrap.length) {
            $textWrap = $('<span>', { class: 'll-word-recording-text' }).appendTo($row);
        }

        let $main = $textWrap.find('.ll-word-recording-text-main').first();
        if (parts.text) {
            if (!$main.length) {
                $main = $('<span>', { class: 'll-word-recording-text-main' }).appendTo($textWrap);
            }
            $main.text(parts.text);
        } else {
            $main.remove();
        }

        let $translation = $textWrap.find('.ll-word-recording-text-translation').first();
        if (parts.translation) {
            if (!$translation.length) {
                $translation = $('<span>', { class: 'll-word-recording-text-translation' }).appendTo($textWrap);
            }
            $translation.text(parts.translation);
        } else {
            $translation.remove();
        }

        let $ipa = $textWrap.find('.ll-word-recording-ipa').first();
        if (parts.ipa) {
            if (!$ipa.length) {
                $ipa = $('<span>', { class: 'll-word-recording-ipa ll-ipa' }).appendTo($textWrap);
            }
            $ipa.text(normalizeIpaOutput(parts.ipa));
        } else {
            $ipa.remove();
        }

        if (!$textWrap.children().length) {
            $textWrap.remove();
        }
    }

    const ipaAllowedChar = /[a-z\u00C0-\u02FF\u0300-\u036F\u0370-\u03FF\u1D00-\u1DFF\u{10784}\. ]/u;
    const ipaCombiningMark = /[\u0300-\u036F]/u;
    const ipaPostModifier = /[\u02B0-\u02B8\u02D0\u02D1\u02E0-\u02E4\u1D2C-\u1D6A\u1D9B-\u1DBF\u2070-\u209F\u{10784}]/u;
    const ipaStressMarker = /[\u02C8\u02CC]/u;
    const ipaUppercaseMap = {
        'R': 'ʀ',
        'B': 'ʙ',
        'G': 'ɢ'
    };
    const ipaSuperscriptMap = {
        'a': 'ᵃ',
        'b': 'ᵇ',
        'c': 'ᶜ',
        'd': 'ᵈ',
        'e': 'ᵉ',
        'f': 'ᶠ',
        'g': 'ᵍ',
        'h': 'ʰ',
        'i': 'ᶦ',
        'j': 'ʲ',
        'k': 'ᵏ',
        'l': 'ˡ',
        'm': 'ᵐ',
        'n': 'ᶰ',
        'o': 'ᵒ',
        'p': 'ᵖ',
        'r': 'ʳ',
        's': 'ˢ',
        't': 'ᵗ',
        'u': 'ᵘ',
        'v': 'ᵛ',
        'w': 'ʷ',
        'x': 'ˣ',
        'y': 'ʸ',
        'z': 'ᶻ',
        'A': 'ᴬ',
        'B': '\u{10784}',
        'D': 'ᴰ',
        'E': 'ᴱ',
        'G': 'ᴳ',
        'H': 'ᴴ',
        'I': 'ᴵ',
        'J': 'ᴶ',
        'K': 'ᴷ',
        'L': 'ᴸ',
        'M': 'ᴹ',
        'N': 'ᴺ',
        'O': 'ᴼ',
        'P': 'ᴾ',
        'R': 'ᴿ',
        'T': 'ᵀ',
        'U': 'ᵁ',
        'W': 'ᵂ',
        'Y': 'ᵞ',
        'ʙ': '\u{10784}',
        'ɢ': 'ᴳ',
        'ʜ': 'ᴴ',
        'ɪ': 'ᴵ',
        'ʟ': 'ᴸ',
        'ɴ': 'ᴺ',
        'ʀ': 'ᴿ',
        'ʊ': 'ᵁ',
        'ʏ': 'ᵞ'
    };
    const ipaMatchMap = {
        '\u027e': 'r',
        '\u0279': 'r',
        '\u027b': 'r',
        '\u0280': 'r',
        '\u0281': 'r',
        '\u027d': 'r',
        '\u029c': 'h',
        '\u0266': 'h',
        '\u0283': 'sh',
        '\u0292': 'zh',
        '\u03b8': 'th',
        '\u00f0': 'th',
        '\u014b': 'ng',
        '\u0272': 'ny',
        '\u0250': 'a',
        '\u0251': 'a',
        '\u0252': 'o',
        '\u00e6': 'a',
        '\u025b': 'e',
        '\u025c': 'e',
        '\u0259': 'e',
        '\u026a': 'i',
        '\u028a': 'u',
        '\u028c': 'u',
        '\u0254': 'o',
        '\u026f': 'u',
        '\u0268': 'i',
        '\u0289': 'u',
        '\u00f8': 'o',
        '\u0153': 'oe',
        '\u0276': 'oe',
        '\u0261': 'g',
        '\u0263': 'g',
        '\u028b': 'v'
    };
    let activeIpaInput = null;
    let activeIpaSelection = null;
    let lastIpaEdit = { input: null, type: null, time: 0 };
    let waveformContext = null;
    const waveformCache = new Map();
    const waveformPending = new Map();

    function normalizeIpaChar(ch) {
        if (ch === '\u1D2E') {
            return '\u{10784}';
        }
        if (ch === "'" || ch === '’') {
            return '\u02C8';
        }
        if (/[A-Z]/.test(ch)) {
            return ipaUppercaseMap[ch] || ch.toLowerCase();
        }
        return ch;
    }

    function sanitizeIpaValue(value) {
        const raw = (value || '').toString();
        const chars = Array.from(raw);
        let out = '';
        chars.forEach(function (ch) {
            const normalized = normalizeIpaChar(ch);
            if (ipaAllowedChar.test(normalized)) {
                out += normalized;
            }
        });
        const hadTrailingSpace = /\s$/.test(out);
        out = out.replace(/\s+/g, ' ').replace(/^\s+/, '');
        if (hadTrailingSpace) {
            out = out.replace(/\s+$/, ' ');
        } else {
            out = out.trim();
        }
        return out;
    }

    function updateIpaSelection(input) {
        if (!input || typeof input.selectionStart !== 'number' || typeof input.selectionEnd !== 'number') {
            activeIpaSelection = null;
            return;
        }
        activeIpaSelection = {
            start: input.selectionStart,
            end: input.selectionEnd
        };
        if (input === activeIpaInput) {
            refreshIpaKeyboardForInput(input);
        }
    }

    function setLastIpaEdit(input, type) {
        lastIpaEdit = {
            input: input || null,
            type: type || null,
            time: Date.now()
        };
    }

    function getLastIpaEditType(input) {
        if (!input || lastIpaEdit.input !== input) {
            return null;
        }
        if (!lastIpaEdit.time || (Date.now() - lastIpaEdit.time) > 1500) {
            return null;
        }
        return lastIpaEdit.type || null;
    }

    function updateLastIpaEditFromInputEvent(event, input) {
        const original = event && event.originalEvent ? event.originalEvent : event;
        const inputType = original && original.inputType ? original.inputType : '';
        if (!inputType) {
            return;
        }
        if (inputType.indexOf('delete') === 0) {
            setLastIpaEdit(input, 'delete');
        } else if (inputType.indexOf('insert') === 0) {
            setLastIpaEdit(input, 'insert');
        } else {
            setLastIpaEdit(input, null);
        }
    }

    function toIpaSuperscript(text) {
        const chars = Array.from((text || '').toString());
        if (!chars.length) { return ''; }

        const clusters = [];
        chars.forEach(function (ch) {
            if (ipaCombiningMark.test(ch)) {
                if (!clusters.length) {
                    clusters.push([ch]);
                } else {
                    clusters[clusters.length - 1].push(ch);
                }
                return;
            }
            clusters.push([ch]);
        });

        return clusters.map(function (cluster) {
            if (!cluster.length) { return ''; }
            const base = cluster[0];
            if (ipaCombiningMark.test(base)) {
                return cluster.join('');
            }
            let baseOut = ipaSuperscriptMap[base] || base;
            return baseOut + cluster.slice(1).join('');
        }).join('');
    }

    function getIpaSelectionBeforeCursor(input, cursor) {
        if (!input || typeof cursor !== 'number' || cursor <= 0) {
            return null;
        }
        const value = (input.value || '').toString();
        if (!value) {
            return null;
        }
        const codePoints = [];
        let index = 0;
        for (const ch of value) {
            const start = index;
            const end = index + ch.length;
            codePoints.push({ ch: ch, start: start, end: end });
            index = end;
        }
        if (!codePoints.length) {
            return null;
        }
        let lastIndex = -1;
        for (let i = 0; i < codePoints.length; i += 1) {
            if (codePoints[i].end <= cursor) {
                lastIndex = i;
            } else {
                break;
            }
        }
        if (lastIndex < 0) {
            return null;
        }
        let startIndex = lastIndex;
        if (isCombiningMark(codePoints[lastIndex].ch) || isPostModifier(codePoints[lastIndex].ch)) {
            while (startIndex >= 0
                && (isCombiningMark(codePoints[startIndex].ch) || isPostModifier(codePoints[startIndex].ch))) {
                startIndex -= 1;
            }
            if (startIndex < 0) {
                startIndex = lastIndex;
            }
        }
        return {
            start: codePoints[startIndex].start,
            end: codePoints[lastIndex].end
        };
    }

    function applySuperscriptToSelection(input) {
        if (!input) { return; }
        const hasNativeSelection = (typeof input.selectionStart === 'number' && typeof input.selectionEnd === 'number');
        let selection = hasNativeSelection ? { start: input.selectionStart, end: input.selectionEnd } : null;
        if (!selection || selection.end <= selection.start) {
            selection = hasNativeSelection
                ? getIpaSelectionBeforeCursor(input, input.selectionStart)
                : null;
        }
        if ((!selection || selection.end <= selection.start) && !hasNativeSelection && activeIpaSelection) {
            selection = activeIpaSelection;
        }
        if (!selection || selection.end <= selection.start) {
            return;
        }
        const value = (input.value || '').toString();
        const selected = value.slice(selection.start, selection.end);
        if (!selected) { return; }
        const transformed = toIpaSuperscript(selected);
        if (transformed === selected) { return; }
        input.value = value.slice(0, selection.start) + transformed + value.slice(selection.end);
        const newEnd = selection.start + transformed.length;
        if (input.setSelectionRange) {
            input.setSelectionRange(selection.start, newEnd);
        }
        setLastIpaEdit(input, 'insert');
        $(input).trigger('input');
        updateIpaSelection(input);
    }

    function extractIpaSpecialChars(value) {
        const tokens = tokenizeIpa(value);
        const found = [];
        const seen = new Set();
        tokens.forEach(function (token) {
            if (!isSpecialIpaToken(token)) { return; }
            if (seen.has(token)) { return; }
            seen.add(token);
            found.push(token);
        });
        return found;
    }

    function isIpaSeparator(ch) {
        return ch === '.' || /\s/.test(ch);
    }

    function isCombiningMark(ch) {
        return ipaCombiningMark.test(ch);
    }

    function isPostModifier(ch) {
        return ipaPostModifier.test(ch);
    }

    function isIpaStressMarker(ch) {
        return ipaStressMarker.test(ch);
    }

    function isTieBar(ch) {
        return ch === '\u0361' || ch === '\u035C';
    }

    function stripStressMarkersFromToken(token) {
        if (!token) { return ''; }
        return token.replace(/\u02C8|\u02CC/g, '');
    }

    function filterIpaTokensForMapping(tokens) {
        if (!Array.isArray(tokens) || !tokens.length) { return []; }
        const cleaned = [];
        tokens.forEach(function (token) {
            if (!token) { return; }
            const stripped = stripStressMarkersFromToken(token);
            if (!stripped) { return; }
            if (isIpaStressMarker(stripped)) { return; }
            cleaned.push(stripped);
        });
        return cleaned;
    }

    function tokenizeIpa(value) {
        const sanitized = sanitizeIpaValue(value);
        if (!sanitized) { return []; }
        const chars = Array.from(sanitized);
        const tokens = [];
        let buffer = '';
        let pending = '';
        let tiePending = false;

        chars.forEach(function (ch) {
            if (isIpaSeparator(ch)) {
                if (buffer) {
                    tokens.push(buffer);
                    buffer = '';
                }
                pending = '';
                tiePending = false;
                return;
            }

            if (isCombiningMark(ch)) {
                if (buffer) {
                    buffer += ch;
                } else {
                    pending += ch;
                }
                if (isTieBar(ch) && buffer) {
                    tiePending = true;
                }
                return;
            }

            if (isPostModifier(ch)) {
                if (buffer) {
                    buffer += ch;
                    return;
                }
                if (pending) {
                    buffer = pending + ch;
                    pending = '';
                    tiePending = false;
                    return;
                }
                buffer = ch;
                tiePending = false;
                return;
            }

            if (!buffer) {
                buffer = pending + ch;
                pending = '';
                tiePending = false;
                return;
            }

            if (tiePending) {
                buffer += ch;
                tiePending = false;
                return;
            }

            tokens.push(buffer);
            buffer = pending + ch;
            pending = '';
            tiePending = false;
        });

        if (buffer) {
            tokens.push(buffer);
        }

        return tokens;
    }

    function isSpecialIpaToken(token) {
        if (!token) { return false; }
        if (isIpaSeparator(token)) { return false; }
        if (ipaCombiningMark.test(token)) { return true; }
        if (/[^a-z]/u.test(token)) { return true; }
        return false;
    }

    function mergeIpaSpecialChars(newChars) {
        if (!Array.isArray(newChars) || !newChars.length) { return false; }
        let updated = false;
        newChars.forEach(function (ch) {
            if (ipaSpecialChars.indexOf(ch) === -1) {
                ipaSpecialChars.push(ch);
                updated = true;
            }
        });
        return updated;
    }

    function ensureWaveformContext() {
        if (waveformContext) { return waveformContext; }
        const Ctor = window.AudioContext || window.webkitAudioContext;
        if (!Ctor) { return null; }
        try {
            waveformContext = new Ctor();
        } catch (_) {
            waveformContext = null;
        }
        return waveformContext;
    }

    function fetchAudioArrayBuffer(url) {
        if (window.fetch) {
            return fetch(url, { credentials: 'same-origin' }).then(function (resp) {
                if (!resp.ok) {
                    throw new Error('Waveform fetch failed');
                }
                return resp.arrayBuffer();
            });
        }
        return new Promise(function (resolve, reject) {
            const xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.responseType = 'arraybuffer';
            xhr.onload = function () {
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(xhr.response);
                } else {
                    reject(new Error('Waveform fetch failed'));
                }
            };
            xhr.onerror = function () {
                reject(new Error('Waveform fetch failed'));
            };
            xhr.send();
        });
    }

    function decodeAudioBuffer(ctx, buffer) {
        return new Promise(function (resolve, reject) {
            if (!ctx) {
                reject(new Error('Missing AudioContext'));
                return;
            }
            const bufferCopy = buffer.slice(0);
            const done = function (decoded) { resolve(decoded); };
            const fail = function (err) { reject(err || new Error('Decode failed')); };
            const result = ctx.decodeAudioData(bufferCopy, done, fail);
            if (result && typeof result.then === 'function') {
                result.then(resolve).catch(fail);
            }
        });
    }

    function loadWaveformBuffer(url) {
        if (!url) { return Promise.reject(new Error('Missing URL')); }
        if (waveformCache.has(url)) {
            return Promise.resolve(waveformCache.get(url));
        }
        if (waveformPending.has(url)) {
            return waveformPending.get(url);
        }
        const ctx = ensureWaveformContext();
        if (!ctx) { return Promise.reject(new Error('No AudioContext')); }

        const promise = fetchAudioArrayBuffer(url)
            .then(function (buffer) { return decodeAudioBuffer(ctx, buffer); })
            .then(function (decoded) {
                waveformCache.set(url, decoded);
                waveformPending.delete(url);
                return decoded;
            })
            .catch(function (err) {
                waveformPending.delete(url);
                throw err;
            });

        waveformPending.set(url, promise);
        return promise;
    }

    function drawWaveform(canvas, container, audioBuffer) {
        if (!canvas || !container || !audioBuffer) { return; }
        const rect = container.getBoundingClientRect();
        const width = Math.floor(rect.width);
        const height = Math.floor(rect.height);
        if (!width || !height) { return; }

        const dpr = window.devicePixelRatio || 1;
        canvas.width = width * dpr;
        canvas.height = height * dpr;
        canvas.style.width = width + 'px';
        canvas.style.height = height + 'px';

        const ctx = canvas.getContext('2d');
        if (!ctx) { return; }
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, width, height);

        const channelData = audioBuffer.getChannelData(0);
        if (!channelData || !channelData.length) { return; }

        const samplesPerPixel = Math.max(1, Math.floor(channelData.length / width));
        const centerY = height / 2;

        ctx.fillStyle = '#2ecc71';

        for (let x = 0; x < width; x++) {
            const start = x * samplesPerPixel;
            const end = Math.min(start + samplesPerPixel, channelData.length);
            let min = 1;
            let max = -1;

            for (let i = start; i < end; i += 1) {
                const sample = channelData[i];
                if (sample < min) { min = sample; }
                if (sample > max) { max = sample; }
            }

            const yTop = centerY - (max * centerY);
            const yBottom = centerY - (min * centerY);
            const height = yBottom - yTop;
            ctx.fillRect(x, yTop, 1, height);
        }
    }

    function renderIpaWaveform($recording) {
        if (!$recording || !$recording.length) { return; }
        const container = $recording.find('[data-ll-ipa-waveform]').get(0);
        const canvas = container ? container.querySelector('.ll-word-edit-ipa-waveform-canvas') : null;
        if (!container || !canvas) { return; }
        const audio = $recording.find('.ll-word-edit-ipa-audio-player').get(0);
        const url = audio ? (audio.currentSrc || audio.src || '') : '';
        if (!url) { return; }

        loadWaveformBuffer(url).then(function (buffer) {
            if (!document.body.contains(container)) { return; }
            drawWaveform(canvas, container, buffer);
        }).catch(function () {});
    }

    function normalizeTextSegment(segment) {
        if (!segment) { return ''; }
        let text = segment.toString().toLocaleLowerCase();
        if (text.normalize) {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        text = text.replace(/[^a-z]/g, '');
        return text;
    }

    function normalizeIpaSegment(segment) {
        if (!segment) { return ''; }
        let text = segment.toString().toLocaleLowerCase().replace(/[\s\.]+/g, '');
        if (text.normalize) {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        let out = '';
        for (const ch of text) {
            if (ch >= 'a' && ch <= 'z') {
                out += ch;
                continue;
            }
            if (ipaMatchMap[ch]) {
                out += ipaMatchMap[ch];
            }
        }
        return out;
    }

    function normalizeIpaSegmentWithLength(segment) {
        if (!segment) { return ''; }
        let text = segment.toString().toLocaleLowerCase().replace(/[\s\.]+/g, '');
        if (text.normalize) {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        let out = '';
        let last = '';
        for (const ch of text) {
            if (ch === '\u02d0' || ch === '\u02d1') {
                if (last) {
                    out += last;
                }
                continue;
            }
            if (ch >= 'a' && ch <= 'z') {
                out += ch;
                last = ch;
                continue;
            }
            if (ipaMatchMap[ch]) {
                const mapped = ipaMatchMap[ch];
                out += mapped;
                last = mapped;
            }
        }
        return out;
    }

    function levenshteinDistance(a, b) {
        if (a === b) { return 0; }
        const alen = a.length;
        const blen = b.length;
        if (!alen) { return blen; }
        if (!blen) { return alen; }
        const row = new Array(blen + 1);
        for (let j = 0; j <= blen; j += 1) {
            row[j] = j;
        }
        for (let i = 1; i <= alen; i += 1) {
            let prev = i - 1;
            row[0] = i;
            for (let j = 1; j <= blen; j += 1) {
                const temp = row[j];
                const cost = a[i - 1] === b[j - 1] ? 0 : 1;
                row[j] = Math.min(
                    row[j] + 1,
                    row[j - 1] + 1,
                    prev + cost
                );
                prev = temp;
            }
        }
        return row[blen];
    }

    function similarityScore(textSegment, ipaSegment) {
        const textNorm = normalizeTextSegment(textSegment);
        if (!textNorm) { return 0; }
        const ipaNorm = normalizeIpaSegment(ipaSegment);
        const ipaExpanded = normalizeIpaSegmentWithLength(ipaSegment);
        if (!ipaNorm && !ipaExpanded) { return 0; }

        const scoreFor = function (norm) {
            if (!norm) { return 0; }
            if (textNorm === norm) { return 1; }
            const distance = levenshteinDistance(textNorm, norm);
            const maxLen = Math.max(textNorm.length, norm.length);
            if (!maxLen) { return 0; }
            const score = 1 - (distance / maxLen);
            return Math.max(0, Math.min(1, score));
        };

        let best = scoreFor(ipaNorm);
        if (ipaExpanded && ipaExpanded !== ipaNorm) {
            best = Math.max(best, scoreFor(ipaExpanded));
        }
        return best;
    }

    function alignTextToIpa(letters, tokens) {
        if (!letters.length || !tokens.length) { return null; }
        const matchThreshold = 0.55;
        const skipPenalty = 0.25;
        const multiPenalty = 0.05;
        const n = letters.length;
        const m = tokens.length;
        const tokenNorms = tokens.map(function (token) { return normalizeIpaSegment(token); });
        const comboNorms = [];
        for (let idx = 0; idx < (m - 1); idx += 1) {
            comboNorms[idx] = normalizeIpaSegment(tokens[idx] + tokens[idx + 1]);
        }
        const dp = Array.from({ length: n + 1 }, () => Array(m + 1).fill(null));
        dp[0][0] = { score: 0, prev: null };

        const update = function (i, j, score, prev) {
            const cell = dp[i][j];
            if (!cell || score > cell.score) {
                dp[i][j] = { score: score, prev: prev };
            }
        };

        for (let i = 0; i <= n; i += 1) {
            for (let j = 0; j <= m; j += 1) {
                const cell = dp[i][j];
                if (!cell) { continue; }

                if (i < n) {
                    update(i + 1, j, cell.score - skipPenalty, { type: 'skip-text', i: i, j: j });
                }
                if (j < m) {
                    update(i, j + 1, cell.score - skipPenalty, { type: 'skip-token', i: i, j: j });
                }

                if (i < n && j < m) {
                    const score = similarityScore(letters[i], tokens[j]);
                    if (score >= matchThreshold) {
                        update(i + 1, j + 1, cell.score + score, {
                            type: 'match',
                            i: i,
                            j: j,
                            text: letters[i],
                            ipa: tokens[j],
                            textLen: 1,
                            tokenLen: 1,
                            score: score
                        });
                    }
                }
                if (i < n && (j + 1) < m) {
                    const comboNorm = comboNorms[j] || '';
                    if (comboNorm) {
                        const normA = tokenNorms[j] || '';
                        const normB = tokenNorms[j + 1] || '';
                        if (comboNorm !== normA && comboNorm !== normB) {
                            const ipaSegment = tokens[j] + tokens[j + 1];
                            const score = similarityScore(letters[i], ipaSegment);
                            if (score >= matchThreshold) {
                                update(i + 1, j + 2, cell.score + score - multiPenalty, {
                                    type: 'match',
                                    i: i,
                                    j: j,
                                    text: letters[i],
                                    ipa: ipaSegment,
                                    textLen: 1,
                                    tokenLen: 2,
                                    score: score
                                });
                            }
                        }
                    }
                }
                if ((i + 1) < n && j < m) {
                    const textSegment = letters[i] + letters[i + 1];
                    const score = similarityScore(textSegment, tokens[j]);
                    if (score >= matchThreshold) {
                        update(i + 2, j + 1, cell.score + score - multiPenalty, {
                            type: 'match',
                            i: i,
                            j: j,
                            text: textSegment,
                            ipa: tokens[j],
                            textLen: 2,
                            tokenLen: 1,
                            score: score
                        });
                    }
                }
            }
        }

        const endCell = dp[n][m];
        if (!endCell) { return null; }

        let matches = [];
        let matchedLetters = 0;
        let matchedTokens = 0;
        let totalScore = 0;
        let i = n;
        let j = m;

        while (i > 0 || j > 0) {
            const cell = dp[i][j];
            if (!cell || !cell.prev) { break; }
            const prev = cell.prev;
            if (prev.type === 'match') {
                matches.push({
                    textIndex: prev.i,
                    tokenIndex: prev.j,
                    textLength: prev.textLen,
                    tokenLength: prev.tokenLen,
                    score: prev.score
                });
                matchedLetters += prev.textLen;
                matchedTokens += prev.tokenLen;
                totalScore += prev.score;
            }
            i = prev.i || 0;
            j = prev.j || 0;
        }

        if (!matches.length) { return null; }
        matches = matches.reverse();
        const avgScore = totalScore / matches.length;
        const letterCoverage = matchedLetters / n;
        const tokenCoverage = matchedTokens / m;
        if (avgScore < 0.55 || letterCoverage < 0.55 || tokenCoverage < 0.45) {
            return null;
        }

        return matches;
    }

    function getTextLetters(value) {
        const raw = (value || '').toString();
        if (!raw) { return []; }
        const letters = [];
        for (const ch of raw) {
            if (/\p{L}/u.test(ch)) {
                letters.push(ch.toLocaleLowerCase());
            }
        }
        return letters;
    }

    function getLetterIndexForCursor(letters, tokens, cursorTokenCount, matches, cursorAtBoundary) {
        if (!letters.length) { return -1; }
        if (!tokens.length || cursorTokenCount <= 0) {
            return 0;
        }
        if (!matches || !matches.length) {
            let index = cursorTokenCount;
            if (!cursorAtBoundary && index > 0) {
                index -= 1;
            }
            return Math.min(letters.length - 1, index);
        }
        for (let i = 0; i < matches.length; i += 1) {
            const match = matches[i];
            const tokenStart = match.tokenIndex;
            const tokenEnd = match.tokenIndex + match.tokenLength;
            if (cursorTokenCount <= tokenStart) {
                return match.textIndex;
            }
            if (cursorTokenCount < tokenEnd) {
                return match.textIndex;
            }
            if (cursorTokenCount === tokenEnd) {
                if (cursorAtBoundary) {
                    const nextIndex = match.textIndex + match.textLength;
                    if (nextIndex < letters.length) {
                        return nextIndex;
                    }
                }
                return match.textIndex;
            }
        }
        const last = matches[matches.length - 1];
        const nextIndex = last.textIndex + last.textLength;
        if (nextIndex < letters.length) {
            return nextIndex;
        }
        return letters.length - 1;
    }

    function getIpaShiftOffset($recording) {
        if (!$recording || !$recording.length) { return 0; }
        const raw = $recording.attr('data-ll-ipa-shift');
        const parsed = parseInt(raw, 10);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function setIpaShiftOffset($recording, offset) {
        if (!$recording || !$recording.length) { return; }
        const parsed = parseInt(offset, 10);
        $recording.attr('data-ll-ipa-shift', Number.isFinite(parsed) ? parsed : 0);
    }

    function clampIpaShift(baseIndex, offset, totalLetters) {
        if (!totalLetters || baseIndex < 0) {
            return { offset: 0, minOffset: 0, maxOffset: 0 };
        }
        const minOffset = -baseIndex;
        const maxOffset = (totalLetters - 1) - baseIndex;
        let next = Number.isFinite(offset) ? offset : 0;
        if (next < minOffset) { next = minOffset; }
        if (next > maxOffset) { next = maxOffset; }
        return { offset: next, minOffset: minOffset, maxOffset: maxOffset };
    }

    function getIpaSuggestionsForLetter(letters, letterIndex) {
        if (!ipaLetterMap || !Object.keys(ipaLetterMap).length) { return []; }
        if (!letters.length || letterIndex < 0 || letterIndex >= letters.length) { return []; }

        const suggestions = [];
        const seen = new Set();
        const digraph = letters[letterIndex] + (letters[letterIndex + 1] || '');
        const candidates = [];
        if (digraph && ipaLetterMap[digraph]) {
            candidates.push(digraph);
        }
        const letterKey = letters[letterIndex];
        if (letterKey && ipaLetterMap[letterKey]) {
            candidates.push(letterKey);
        }
        candidates.forEach(function (key) {
            const entries = ipaLetterMap[key] || [];
            entries.forEach(function (entry) {
                if (!entry || seen.has(entry)) { return; }
                seen.add(entry);
                suggestions.push(entry);
            });
        });

        return suggestions;
    }

    function getIpaSuggestionState($input) {
        const state = {
            suggestions: [],
            letters: [],
            letterIndex: -1,
            baseIndex: -1,
            offset: 0,
            minOffset: 0,
            maxOffset: 0,
            highlightLength: 1,
            textValue: ''
        };
        if (!$input || !$input.length) { return state; }
        const $recording = $input.closest('.ll-word-edit-recording');
        if (!$recording.length) { return state; }
        const textValue = ($recording.find('[data-ll-recording-input="text"]').val() || '').toString();
        state.textValue = textValue;
        const letters = getTextLetters(textValue);
        state.letters = letters;
        if (!letters.length) { return state; }
        const input = $input.get(0);
        if (!input) { return state; }
        const value = (input.value || '').toString();
        const tokens = filterIpaTokensForMapping(tokenizeIpa(value));
        const cursor = (typeof input.selectionStart === 'number') ? input.selectionStart : value.length;
        const beforeTokens = filterIpaTokensForMapping(tokenizeIpa(value.slice(0, cursor)));
        const tokensBefore = beforeTokens.length;
        const prefixTokens = tokens.slice(0, tokensBefore);
        const cursorAtEnd = cursor >= value.length;
        const cursorAtBoundary = cursorAtEnd || beforeTokens.join('') === prefixTokens.join('');
        const matches = alignTextToIpa(letters, tokens);
        const lastEditType = getLastIpaEditType(input);
        const advanceAtBoundary = lastEditType !== 'delete';
        const baseIndex = getLetterIndexForCursor(
            letters,
            tokens,
            tokensBefore,
            matches,
            cursorAtBoundary && advanceAtBoundary
        );
        state.baseIndex = baseIndex;
        if (baseIndex < 0 || baseIndex >= letters.length) { return state; }

        const shift = clampIpaShift(baseIndex, getIpaShiftOffset($recording), letters.length);
        state.offset = shift.offset;
        state.minOffset = shift.minOffset;
        state.maxOffset = shift.maxOffset;
        if (shift.offset !== getIpaShiftOffset($recording)) {
            setIpaShiftOffset($recording, shift.offset);
        }
        const letterIndex = baseIndex + shift.offset;
        state.letterIndex = letterIndex;
        state.suggestions = getIpaSuggestionsForLetter(letters, letterIndex);
        const digraph = letters[letterIndex] + (letters[letterIndex + 1] || '');
        if (digraph && ipaLetterMap[digraph]) {
            state.highlightLength = 2;
        }
        return state;
    }

    function getIpaSuggestionsForInput($input) {
        return getIpaSuggestionState($input).suggestions;
    }

    function renderIpaTargetText($target, textValue, highlightIndex, highlightLength) {
        if (!$target || !$target.length) { return; }
        const el = $target.get(0);
        if (!el) { return; }
        while (el.firstChild) {
            el.removeChild(el.firstChild);
        }
        const text = (textValue || '').toString();
        if (!text) { return; }
        const spanLength = Math.max(1, parseInt(highlightLength || 1, 10));
        const highlightStart = typeof highlightIndex === 'number' ? highlightIndex : -1;
        const highlightEnd = highlightStart + spanLength - 1;
        const fragment = document.createDocumentFragment();
        let letterIndex = -1;
        for (const ch of text) {
            const span = document.createElement('span');
            span.textContent = ch;
            if (/\p{L}/u.test(ch)) {
                letterIndex += 1;
                if (letterIndex >= highlightStart && letterIndex <= highlightEnd) {
                    span.className = 'll-word-edit-ipa-target-letter';
                }
            }
            fragment.appendChild(span);
        }
        el.appendChild(fragment);
    }

    function updateIpaTargetRow($recording, state) {
        if (!$recording || !$recording.length) { return; }
        const $target = $recording.find('[data-ll-ipa-target]').first();
        if (!$target.length) { return; }
        const $text = $recording.find('[data-ll-ipa-target-text]').first();
        const $prev = $recording.find('[data-ll-ipa-shift="prev"]').first();
        const $next = $recording.find('[data-ll-ipa-shift="next"]').first();
        const letters = state && Array.isArray(state.letters) ? state.letters : [];
        if (!letters.length || !state || state.baseIndex < 0) {
            $target.attr('aria-hidden', 'true');
            if ($text.length) { $text.empty(); }
            if ($prev.length) { $prev.prop('disabled', true); }
            if ($next.length) { $next.prop('disabled', true); }
            return;
        }
        $target.attr('aria-hidden', 'false');
        renderIpaTargetText($text, state.textValue, state.letterIndex, state.highlightLength);
        const minOffset = Number.isFinite(state.minOffset) ? state.minOffset : 0;
        const maxOffset = Number.isFinite(state.maxOffset) ? state.maxOffset : 0;
        const offset = Number.isFinite(state.offset) ? state.offset : 0;
        if ($prev.length) { $prev.prop('disabled', offset <= minOffset); }
        if ($next.length) { $next.prop('disabled', offset >= maxOffset); }
    }

    function renderIpaSuggestionRow($keyboard, suggestions) {
        if (!$keyboard || !$keyboard.length) { return 0; }
        $keyboard.empty();

        if (!Array.isArray(suggestions) || !suggestions.length) {
            return 0;
        }

        const merged = [];
        const seen = new Set();
        suggestions.forEach(function (ch) {
            if (!ch || seen.has(ch)) { return; }
            seen.add(ch);
            merged.push(ch);
        });

        if (!merged.length) {
            return 0;
        }

        merged.forEach(function (ch) {
            $('<button>', {
                type: 'button',
                class: 'll-word-ipa-key',
                text: ch,
                'data-ipa-char': ch,
                'aria-label': ch
            }).appendTo($keyboard);
        });

        return merged.length;
    }

    function renderIpaKeyboard($keyboard, chars, skipChars) {
        if (!$keyboard || !$keyboard.length) { return 0; }
        $keyboard.empty();

        const merged = [];
        const seen = new Set();
        const skipSet = new Set(Array.isArray(skipChars) ? skipChars : []);
        const pushUnique = function (ch) {
            if (!ch || seen.has(ch) || skipSet.has(ch)) { return; }
            seen.add(ch);
            merged.push(ch);
        };

        ipaCommonChars.forEach(pushUnique);
        if (Array.isArray(chars)) {
            chars.forEach(pushUnique);
        }

        if (!merged.length) {
            return 0;
        }

        merged.forEach(function (ch) {
            $('<button>', {
                type: 'button',
                class: 'll-word-ipa-key',
                text: ch,
                'data-ipa-char': ch,
                'aria-label': ch
            }).appendTo($keyboard);
        });

        return merged.length;
    }

    function hideIpaAudio() {
        $('[data-ll-ipa-audio]').attr('aria-hidden', 'true').each(function () {
            const audio = $(this).find('audio').get(0);
            if (audio && !audio.paused) {
                audio.pause();
            }
        });
        $('[data-ll-ipa-waveform]').attr('aria-hidden', 'true');
    }

    function showIpaKeyboard($input) {
        if (!$input || !$input.length) { return; }
        const $recording = $input.closest('.ll-word-edit-recording');
        const $keyboard = $recording.find('[data-ll-ipa-keyboard]').first();
        const $suggestions = $recording.find('[data-ll-ipa-suggestions]').first();
        if (!$keyboard.length) { return; }
        const inputEl = $input.get(0);
        $('[data-ll-ipa-keyboard]').attr('aria-hidden', 'true');
        $('[data-ll-ipa-suggestions]').attr('aria-hidden', 'true');
        $('[data-ll-ipa-target]').attr('aria-hidden', 'true');
        $('[data-ll-ipa-superscript]').attr('aria-hidden', 'true');
        const $audio = $recording.find('[data-ll-ipa-audio]').first();
        const shouldAdjustScroll = $audio.length && $audio.attr('aria-hidden') !== 'false';
        const initialTop = (shouldAdjustScroll && inputEl) ? inputEl.getBoundingClientRect().top : null;
        $('[data-ll-ipa-audio]').not($audio).attr('aria-hidden', 'true').each(function () {
            const audio = $(this).find('audio').get(0);
            if (audio && !audio.paused) {
                audio.pause();
            }
        });
        const state = getIpaSuggestionState($input);
        const suggestionCount = renderIpaSuggestionRow($suggestions, state.suggestions);
        const keyCount = renderIpaKeyboard($keyboard, ipaSpecialChars, state.suggestions);
        updateIpaTargetRow($recording, state);
        if (keyCount > 0) {
            $keyboard.attr('aria-hidden', 'false');
        } else {
            $keyboard.attr('aria-hidden', 'true');
        }
        if ($suggestions.length) {
            $suggestions.attr('aria-hidden', suggestionCount > 0 ? 'false' : 'true');
        }
        if (keyCount > 0 || suggestionCount > 0) {
            $recording.find('[data-ll-ipa-superscript]').attr('aria-hidden', 'false');
            activeIpaInput = $input.get(0);
        } else {
            activeIpaInput = null;
        }
        if ($audio.length) {
            $audio.attr('aria-hidden', 'false');
            $recording.find('[data-ll-ipa-waveform]').attr('aria-hidden', 'false');
            requestAnimationFrame(function () {
                renderIpaWaveform($recording);
                if (shouldAdjustScroll && inputEl && typeof initialTop === 'number') {
                    const newTop = inputEl.getBoundingClientRect().top;
                    const delta = newTop - initialTop;
                    if (Math.abs(delta) > 0.5) {
                        window.scrollBy(0, delta);
                    }
                }
            });
        }
    }

    function hideIpaKeyboards() {
        $('[data-ll-ipa-keyboard]').attr('aria-hidden', 'true');
        $('[data-ll-ipa-suggestions]').attr('aria-hidden', 'true');
        $('[data-ll-ipa-target]').attr('aria-hidden', 'true');
        $('[data-ll-ipa-superscript]').attr('aria-hidden', 'true');
        hideIpaAudio();
        activeIpaInput = null;
    }

    function refreshIpaKeyboardForInput(input) {
        if (!input) { return; }
        const $input = $(input);
        const $recording = $input.closest('.ll-word-edit-recording');
        if (!$recording.length) { return; }
        const $keyboard = $recording.find('[data-ll-ipa-keyboard]').first();
        const $suggestions = $recording.find('[data-ll-ipa-suggestions]').first();
        if (!$keyboard.length || $keyboard.attr('aria-hidden') === 'true') { return; }
        const state = getIpaSuggestionState($input);
        const suggestionCount = renderIpaSuggestionRow($suggestions, state.suggestions);
        renderIpaKeyboard($keyboard, ipaSpecialChars, state.suggestions);
        if ($suggestions.length) {
            $suggestions.attr('aria-hidden', suggestionCount > 0 ? 'false' : 'true');
        }
        updateIpaTargetRow($recording, state);
    }

    function insertIpaChar(input, ch) {
        if (!input || !ch) { return; }
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? input.value.length;
        const value = input.value || '';
        const nextValue = value.slice(0, start) + ch + value.slice(end);
        input.value = nextValue;
        const cursor = start + ch.length;
        if (input.setSelectionRange) {
            input.setSelectionRange(cursor, cursor);
        }
        setLastIpaEdit(input, 'insert');
        $(input).trigger('input');
    }

    function applyRecordingCaptions($item, recordings) {
        if (!Array.isArray(recordings)) { return; }
        const $wrap = $item.find('.ll-word-recordings').first();
        if (!$wrap.length) { return; }
        const captionMap = {};
        let hasCaption = false;

        recordings.forEach(function (rec) {
            const recId = parseInt(rec.id, 10) || 0;
            if (!recId) { return; }
            const caption = getRecordingCaptionParts(rec.recording_text, rec.recording_translation, rec.recording_ipa);
            captionMap[recId] = caption;
            if (caption.hasCaption) {
                hasCaption = true;
            }
        });

        const $buttons = $wrap.find('.ll-word-grid-recording-btn');
        if (hasCaption) {
            if (!$wrap.hasClass('ll-word-recordings--with-text')) {
                $wrap.empty().addClass('ll-word-recordings--with-text');
                $buttons.each(function () {
                    const $btn = $(this);
                    const recId = parseInt($btn.attr('data-recording-id'), 10) || 0;
                    const caption = recId ? (captionMap[recId] || null) : null;
                    const $row = $('<div>', { class: 'll-word-recording-row' });
                    if (recId) {
                        $row.attr('data-recording-id', recId);
                    }
                    $row.append($btn);
                    renderRecordingCaption($row, caption);
                    $wrap.append($row);
                });
            } else {
                $wrap.find('.ll-word-recording-row').each(function () {
                    const $row = $(this);
                    const recId = parseInt($row.attr('data-recording-id'), 10)
                        || parseInt($row.find('.ll-word-grid-recording-btn').attr('data-recording-id'), 10)
                        || 0;
                    if (!recId) { return; }
                    const caption = captionMap[recId] || null;
                    renderRecordingCaption($row, caption);
                });
            }
        } else if ($wrap.hasClass('ll-word-recordings--with-text')) {
            $wrap.removeClass('ll-word-recordings--with-text').empty();
            $buttons.each(function () {
                $wrap.append(this);
            });
        }
    }

    if (canEdit && ajaxUrl && editNonce) {
        $grids.find('.word-item').each(function () {
            cacheOriginalInputs($(this));
        });

        function getBulkContext($wrap) {
            const $scope = $wrap.closest('[data-ll-vocab-lesson],.ll-vocab-lesson-page');
            const $grid = ($scope.length ? $scope : $(document)).find('[data-ll-word-grid]').first();
            if (!$grid.length) { return null; }
            const wordsetId = parseInt($grid.attr('data-ll-wordset-id'), 10) || 0;
            const categoryId = parseInt($grid.attr('data-ll-category-id'), 10) || 0;
            if (!wordsetId || !categoryId) { return null; }
            return { $grid: $grid, wordsetId: wordsetId, categoryId: categoryId };
        }

        function setBulkBusy($wrap, isBusy) {
            $wrap.find('[data-ll-bulk-pos], [data-ll-bulk-gender], [data-ll-bulk-plurality], [data-ll-bulk-pos-apply], [data-ll-bulk-gender-apply], [data-ll-bulk-plurality-apply]')
                .prop('disabled', isBusy);
            $wrap.attr('aria-busy', isBusy ? 'true' : 'false');
        }

        if ($bulkEditors.length) {
            $bulkEditors.on('click', '[data-ll-bulk-pos-apply]', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const $wrap = $(this).closest('[data-ll-word-grid-bulk]');
                const posSlug = ($wrap.find('[data-ll-bulk-pos]').val() || '').toString();
                if (!posSlug) {
                    setBulkStatus($wrap, bulkMessages.posMissing, true);
                    return;
                }
                const context = getBulkContext($wrap);
                if (!context) {
                    setBulkStatus($wrap, bulkMessages.error, true);
                    return;
                }
                setBulkBusy($wrap, true);
                setBulkStatus($wrap, bulkMessages.saving, false);
                $.post(ajaxUrl, {
                    action: 'll_tools_word_grid_bulk_update',
                    nonce: editNonce,
                    mode: 'pos',
                    part_of_speech: posSlug,
                    wordset_id: context.wordsetId,
                    category_id: context.categoryId
                }).done(function (response) {
                    if (!response || response.success !== true) {
                        setBulkStatus($wrap, bulkMessages.error, true);
                        return;
                    }
                    const data = response.data || {};
                    const ids = Array.isArray(data.word_ids) ? data.word_ids : [];
                    const posData = data.part_of_speech || {};
                    const clearGender = data.gender_cleared === true;
                    const clearPlurality = data.plurality_cleared === true;
                    ids.forEach(function (id) {
                        const wordId = parseInt(id, 10) || 0;
                        if (!wordId) { return; }
                        const $item = context.$grid.find('.word-item[data-word-id="' + wordId + '"]').first();
                        if (!$item.length) { return; }
                        const genderData = clearGender ? { value: '', label: '' } : null;
                        const pluralityData = clearPlurality ? { value: '', label: '' } : null;
                        applyPosMetaUpdate($item, posData, genderData, pluralityData);
                        updateOriginalInputs($item);
                    });
                    updateGridLayouts();
                    setBulkStatus($wrap, formatBulkMessage(bulkMessages.posSuccess, ids.length), false);
                }).fail(function () {
                    setBulkStatus($wrap, bulkMessages.error, true);
                }).always(function () {
                    setBulkBusy($wrap, false);
                });
            });

            $bulkEditors.on('click', '[data-ll-bulk-gender-apply]', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const $wrap = $(this).closest('[data-ll-word-grid-bulk]');
                const genderValue = ($wrap.find('[data-ll-bulk-gender]').val() || '').toString();
                if (!genderValue) {
                    setBulkStatus($wrap, bulkMessages.genderMissing, true);
                    return;
                }
                const context = getBulkContext($wrap);
                if (!context) {
                    setBulkStatus($wrap, bulkMessages.error, true);
                    return;
                }
                setBulkBusy($wrap, true);
                setBulkStatus($wrap, bulkMessages.saving, false);
                $.post(ajaxUrl, {
                    action: 'll_tools_word_grid_bulk_update',
                    nonce: editNonce,
                    mode: 'gender',
                    grammatical_gender: genderValue,
                    wordset_id: context.wordsetId,
                    category_id: context.categoryId
                }).done(function (response) {
                    if (!response || response.success !== true) {
                        setBulkStatus($wrap, bulkMessages.error, true);
                        return;
                    }
                    const data = response.data || {};
                    const ids = Array.isArray(data.word_ids) ? data.word_ids : [];
                    const genderData = data.grammatical_gender || {};
                    ids.forEach(function (id) {
                        const wordId = parseInt(id, 10) || 0;
                        if (!wordId) { return; }
                        const $item = context.$grid.find('.word-item[data-word-id="' + wordId + '"]').first();
                        if (!$item.length) { return; }
                        applyPosMetaUpdate($item, null, genderData, null);
                        updateOriginalInputs($item);
                    });
                    updateGridLayouts();
                    setBulkStatus($wrap, formatBulkMessage(bulkMessages.genderSuccess, ids.length), false);
                }).fail(function () {
                    setBulkStatus($wrap, bulkMessages.error, true);
                }).always(function () {
                    setBulkBusy($wrap, false);
                });
            });

            $bulkEditors.on('click', '[data-ll-bulk-plurality-apply]', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const $wrap = $(this).closest('[data-ll-word-grid-bulk]');
                const pluralityValue = ($wrap.find('[data-ll-bulk-plurality]').val() || '').toString();
                if (!pluralityValue) {
                    setBulkStatus($wrap, bulkMessages.pluralityMissing, true);
                    return;
                }
                const context = getBulkContext($wrap);
                if (!context) {
                    setBulkStatus($wrap, bulkMessages.error, true);
                    return;
                }
                setBulkBusy($wrap, true);
                setBulkStatus($wrap, bulkMessages.saving, false);
                $.post(ajaxUrl, {
                    action: 'll_tools_word_grid_bulk_update',
                    nonce: editNonce,
                    mode: 'plurality',
                    grammatical_plurality: pluralityValue,
                    wordset_id: context.wordsetId,
                    category_id: context.categoryId
                }).done(function (response) {
                    if (!response || response.success !== true) {
                        setBulkStatus($wrap, bulkMessages.error, true);
                        return;
                    }
                    const data = response.data || {};
                    const ids = Array.isArray(data.word_ids) ? data.word_ids : [];
                    const pluralityData = data.grammatical_plurality || {};
                    ids.forEach(function (id) {
                        const wordId = parseInt(id, 10) || 0;
                        if (!wordId) { return; }
                        const $item = context.$grid.find('.word-item[data-word-id="' + wordId + '"]').first();
                        if (!$item.length) { return; }
                        applyPosMetaUpdate($item, null, null, pluralityData);
                        updateOriginalInputs($item);
                    });
                    updateGridLayouts();
                    setBulkStatus($wrap, formatBulkMessage(bulkMessages.pluralitySuccess, ids.length), false);
                }).fail(function () {
                    setBulkStatus($wrap, bulkMessages.error, true);
                }).always(function () {
                    setBulkBusy($wrap, false);
                });
            });
        }

        $grids.on('click', '[data-ll-word-edit-toggle]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $item = $(this).closest('.word-item');
            const $panel = $item.find('[data-ll-word-edit-panel]').first();
            if (!$panel.length) { return; }
            const isOpen = $panel.attr('aria-hidden') === 'false';
            setEditPanelOpen($item, !isOpen);
            if (!isOpen) {
                setEditStatus($item, '');
            }
        });

        $grids.on('click', '[data-ll-word-recordings-toggle]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $item = $(this).closest('.word-item');
            const $panel = $item.find('[data-ll-word-recordings-panel]').first();
            if (!$panel.length) { return; }
            const isOpen = $panel.attr('aria-hidden') === 'false';
            setRecordingsPanelOpen($item, !isOpen);
        });

        $grids.on('change', '[data-ll-word-input="part_of_speech"]', function () {
            const $item = $(this).closest('.word-item');
            const posSlug = ($(this).val() || '').toString();
            setMetaFieldState($item, posSlug);
        });

        $grids.on('focus', '.ll-word-edit-input--ipa', function () {
            showIpaKeyboard($(this));
            updateIpaSelection(this);
        });

        $grids.on('click keyup mouseup', '.ll-word-edit-input--ipa', function () {
            updateIpaSelection(this);
        });

        $grids.on('select touchend', '.ll-word-edit-input--ipa', function () {
            updateIpaSelection(this);
        });

        $grids.on('keydown', '.ll-word-edit-input--ipa', function (event) {
            if (!event || !event.key) { return; }
            if (event.key === 'Backspace' || event.key === 'Delete') {
                setLastIpaEdit(this, 'delete');
            } else if (event.key.length === 1) {
                setLastIpaEdit(this, 'insert');
            } else {
                setLastIpaEdit(this, null);
            }
        });

        $grids.on('input', '.ll-word-edit-input--ipa', function (event) {
            updateLastIpaEditFromInputEvent(event, this);
            const $input = $(this);
            const raw = ($input.val() || '').toString();
            const sanitized = sanitizeIpaValue(raw);
            if (raw !== sanitized) {
                $input.val(sanitized);
            }
            const newChars = extractIpaSpecialChars(sanitized);
            const updated = mergeIpaSpecialChars(newChars);
            if (updated || activeIpaInput === this) {
                showIpaKeyboard($input);
            }
            updateIpaSelection(this);
        });

        $grids.on('input', '[data-ll-recording-input="text"]', function () {
            const $recording = $(this).closest('.ll-word-edit-recording');
            const ipaInput = $recording.find('.ll-word-edit-input--ipa').get(0);
            if (ipaInput && ipaInput === activeIpaInput) {
                refreshIpaKeyboardForInput(ipaInput);
            }
        });

        document.addEventListener('selectionchange', function () {
            if (!activeIpaInput) { return; }
            if (document.activeElement !== activeIpaInput) { return; }
            updateIpaSelection(activeIpaInput);
        });

        $grids.on('mousedown', '[data-ll-ipa-superscript]', function (e) {
            e.preventDefault();
        });

        $grids.on('click', '[data-ll-ipa-superscript]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $wrap = $(this).closest('.ll-word-edit-input-wrap--ipa');
            const input = $wrap.find('.ll-word-edit-input--ipa').get(0) || activeIpaInput;
            if (!input) { return; }
            applySuperscriptToSelection(input);
            input.focus();
        });

        $grids.on('click', '[data-ll-ipa-shift]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if ($(this).prop('disabled')) { return; }
            const $recording = $(this).closest('.ll-word-edit-recording');
            const input = $recording.find('.ll-word-edit-input--ipa').get(0) || activeIpaInput;
            if (!input) { return; }
            activeIpaInput = input;
            const $input = $(input);
            const state = getIpaSuggestionState($input);
            if (!state.letters.length || state.baseIndex < 0) { return; }
            let offset = Number.isFinite(state.offset) ? state.offset : 0;
            const direction = ($(this).attr('data-ll-ipa-shift') || '').toString();
            if (direction === 'prev') {
                offset -= 1;
            } else if (direction === 'next') {
                offset += 1;
            } else {
                return;
            }
            const shift = clampIpaShift(state.baseIndex, offset, state.letters.length);
            setIpaShiftOffset($recording, shift.offset);
            refreshIpaKeyboardForInput(input);
            input.focus();
        });

        $grids.on('click', '.ll-word-ipa-key', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const ch = $(this).attr('data-ipa-char') || $(this).text();
            if (!activeIpaInput || !ch) { return; }
            insertIpaChar(activeIpaInput, ch);
            activeIpaInput.focus();
        });

        $(document).on('click.llWordGridIpa', function (e) {
            if ($(e.target).closest('.ll-word-edit-input--ipa, .ll-word-edit-ipa-keyboard, .ll-word-edit-ipa-suggestions, .ll-word-edit-ipa-target, .ll-word-edit-ipa-superscript').length) {
                return;
            }
            if (activeIpaInput && $(document.activeElement).closest('.ll-word-edit-input--ipa').length) {
                return;
            }
            hideIpaKeyboards();
        });

        $grids.on('click', '[data-ll-word-edit-cancel]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $item = $(this).closest('.word-item');
            restoreOriginalInputs($item);
            setEditStatus($item, '');
            setEditPanelOpen($item, false);
        });

        $grids.on('click', '[data-ll-word-edit-save]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $item = $(this).closest('.word-item');
            const wordId = parseInt($item.data('word-id'), 10) || 0;
            if (!wordId) { return; }

            const wordText = ($item.find('[data-ll-word-input="word"]').val() || '').toString();
            const wordTranslation = ($item.find('[data-ll-word-input="translation"]').val() || '').toString();
            const partOfSpeech = ($item.find('[data-ll-word-input="part_of_speech"]').val() || '').toString();
            const gender = ($item.find('[data-ll-word-input="gender"]').val() || '').toString();
            const plurality = ($item.find('[data-ll-word-input="plurality"]').val() || '').toString();
            const $grid = $item.closest('[data-ll-word-grid]');
            const wordsetId = parseInt($grid.attr('data-ll-wordset-id'), 10) || 0;
            const recordings = [];

            $item.find('.ll-word-edit-recording[data-recording-id]').each(function () {
                const $rec = $(this);
                const recId = parseInt($rec.attr('data-recording-id'), 10) || 0;
                if (!recId) { return; }
                const text = ($rec.find('[data-ll-recording-input="text"]').val() || '').toString();
                const translation = ($rec.find('[data-ll-recording-input="translation"]').val() || '').toString();
                const ipa = normalizeIpaForStorage(($rec.find('[data-ll-recording-input="ipa"]').val() || '').toString());
                recordings.push({ id: recId, text: text, translation: translation, ipa: ipa });
            });

            const $saveBtn = $(this);
            const $cancelBtn = $item.find('[data-ll-word-edit-cancel]');
            $saveBtn.prop('disabled', true);
            $cancelBtn.prop('disabled', true);
            $item.attr('aria-busy', 'true');
            setEditStatus($item, editMessages.saving, false);

            $.post(ajaxUrl, {
                action: 'll_tools_word_grid_update_word',
                nonce: editNonce,
                word_id: wordId,
                word_text: wordText,
                word_translation: wordTranslation,
                part_of_speech: partOfSpeech,
                grammatical_gender: gender,
                grammatical_plurality: plurality,
                wordset_id: wordsetId,
                recordings: recordings
            }).done(function (response) {
                if (!response || response.success !== true) {
                    setEditStatus($item, editMessages.error, true);
                    return;
                }
                const data = response.data || {};
                if (typeof data.word_text === 'string') {
                    $item.find('[data-ll-word-text]').text(data.word_text);
                    $item.find('[data-ll-word-input="word"]').val(data.word_text);
                }
                if (typeof data.word_translation === 'string') {
                    $item.find('[data-ll-word-translation]').text(data.word_translation);
                    $item.find('[data-ll-word-input="translation"]').val(data.word_translation);
                }
                if (Array.isArray(data.recordings)) {
                    data.recordings.forEach(function (rec) {
                        const recId = parseInt(rec.id, 10) || 0;
                        if (!recId) { return; }
                        const $rec = $item.find('.ll-word-edit-recording[data-recording-id="' + recId + '"]');
                        if (!$rec.length) { return; }
                        if (typeof rec.recording_text === 'string') {
                            $rec.find('[data-ll-recording-input="text"]').val(rec.recording_text);
                        }
                        if (typeof rec.recording_translation === 'string') {
                            $rec.find('[data-ll-recording-input="translation"]').val(rec.recording_translation);
                        }
                        if (typeof rec.recording_ipa === 'string') {
                            $rec.find('[data-ll-recording-input="ipa"]').val(rec.recording_ipa);
                        }
                    });
                    applyRecordingCaptions($item, data.recordings);
                }
                if (data.part_of_speech || data.grammatical_gender || data.grammatical_plurality) {
                    applyPosMetaUpdate($item, data.part_of_speech || {}, data.grammatical_gender || {}, data.grammatical_plurality || {});
                }
                updateGridLayouts();
                updateOriginalInputs($item);
                setEditStatus($item, '');
                setRecordingsPanelOpen($item, false);
                setEditPanelOpen($item, false);
            }).fail(function () {
                setEditStatus($item, editMessages.error, true);
            }).always(function () {
                $saveBtn.prop('disabled', false);
                $cancelBtn.prop('disabled', false);
                $item.removeAttr('aria-busy');
            });
        });
    }

    if (canEdit && ajaxUrl && editNonce) {
        const transcribeMessages = Object.assign({
            confirm: '',
            confirmReplace: '',
            confirmClear: '',
            working: 'Transcribing...',
            progress: 'Transcribing %1$d of %2$d...',
            done: 'Transcription complete.',
            none: 'No recordings need text.',
            clearing: 'Clearing captions...',
            cleared: 'Captions cleared.',
            cancelled: 'Transcription cancelled.',
            error: 'Unable to transcribe recordings.'
        }, transcribeI18n || {});

        function applyRecordingUpdate(rec) {
            if (!rec) { return; }
            const wordId = parseInt(rec.word_id, 10) || 0;
            const recId = parseInt(rec.id, 10) || 0;
            if (!wordId || !recId) { return; }
            const $item = $grids.find('.word-item[data-word-id="' + wordId + '"]').first();
            if (!$item.length) { return; }
            const $rec = $item.find('.ll-word-edit-recording[data-recording-id="' + recId + '"]');
            if ($rec.length) {
                if (typeof rec.recording_text === 'string') {
                    $rec.find('[data-ll-recording-input="text"]').val(rec.recording_text);
                }
                if (typeof rec.recording_translation === 'string') {
                    $rec.find('[data-ll-recording-input="translation"]').val(rec.recording_translation);
                }
                if (typeof rec.recording_ipa === 'string') {
                    $rec.find('[data-ll-recording-input="ipa"]').val(rec.recording_ipa);
                }
                const recordings = collectRecordingInputs($item);
                applyRecordingCaptions($item, recordings);
                updateOriginalInputs($item);
            } else {
                const $row = $item.find('.ll-word-recording-row[data-recording-id="' + recId + '"]');
                if ($row.length) {
                    const caption = getRecordingCaptionParts(rec.recording_text, rec.recording_translation, rec.recording_ipa);
                    renderRecordingCaption($row, caption);
                }
            }
            updateRecordingRowWidths();
        }

        function applyWordUpdate(word) {
            if (!word) { return; }
            const wordId = parseInt(word.id || word.word_id, 10) || 0;
            if (!wordId) { return; }
            const $item = $grids.find('.word-item[data-word-id="' + wordId + '"]').first();
            if (!$item.length) { return; }
            if (typeof word.word_text === 'string') {
                $item.find('[data-ll-word-text]').text(word.word_text);
                $item.find('[data-ll-word-input="word"]').val(word.word_text);
            }
            if (typeof word.word_translation === 'string') {
                $item.find('[data-ll-word-translation]').text(word.word_translation);
                $item.find('[data-ll-word-input="translation"]').val(word.word_translation);
            }
            if (word.part_of_speech || word.grammatical_gender || word.grammatical_plurality) {
                applyPosMetaUpdate($item, word.part_of_speech || {}, word.grammatical_gender || {}, word.grammatical_plurality || {});
            }
            updateGridLayouts();
            updateOriginalInputs($item);
        }

        function getTranscribeState($wrap) {
            let state = $wrap.data('llTranscribeState');
            if (!state) {
                state = {
                    active: false,
                    cancelled: false,
                    request: null,
                    pollTimer: null,
                    queue: [],
                    total: 0,
                    completed: 0,
                    hadError: false,
                    force: false
                };
                $wrap.data('llTranscribeState', state);
            }
            return state;
        }

        function setTranscribeControls($wrap, isActive) {
            $wrap.find('[data-ll-transcribe-recordings]').prop('disabled', isActive);
            $wrap.find('[data-ll-transcribe-replace]').prop('disabled', isActive);
            $wrap.find('[data-ll-transcribe-clear]').prop('disabled', isActive);
            $wrap.find('[data-ll-transcribe-cancel]').prop('disabled', !isActive);
        }

        function finishTranscribe($wrap, message, isError) {
            const state = getTranscribeState($wrap);
            state.active = false;
            state.cancelled = false;
            if (state.pollTimer) {
                window.clearTimeout(state.pollTimer);
                state.pollTimer = null;
            }
            state.request = null;
            state.queue = [];
            state.total = 0;
            state.completed = 0;
            state.hadError = false;
            state.force = false;
            setTranscribeControls($wrap, false);
            $wrap.removeAttr('aria-busy');
            if (typeof message === 'string') {
                setTranscribeStatus($wrap, message, !!isError);
            }
        }

        function clearRecordingMetaById(recId) {
            const $rec = $grids.find('.ll-word-edit-recording[data-recording-id="' + recId + '"]');
            if ($rec.length) {
                const $item = $rec.closest('.word-item');
                $rec.find('[data-ll-recording-input="text"]').val('');
                $rec.find('[data-ll-recording-input="translation"]').val('');
                const recordings = collectRecordingInputs($item);
                applyRecordingCaptions($item, recordings);
                updateOriginalInputs($item);
                updateRecordingRowWidths();
                return;
            }
            const $row = $grids.find('.ll-word-recording-row[data-recording-id="' + recId + '"]');
            if ($row.length) {
                const ipa = $row.find('.ll-word-recording-ipa').text() || '';
                renderRecordingCaption($row, getRecordingCaptionParts('', '', ipa));
                updateRecordingRowWidths();
            }
        }

        function applyClearedRecordings(clearedIds) {
            if (!Array.isArray(clearedIds)) { return; }
            clearedIds.forEach(function (recId) {
                const id = parseInt(recId, 10) || 0;
                if (!id) { return; }
                clearRecordingMetaById(id);
            });
        }

        function runLessonTranscription($wrap, lessonId, options) {
            const state = getTranscribeState($wrap);
            if (state.active) { return; }
            const mode = options && options.mode ? options.mode : 'missing';
            const force = !!(options && options.force);
            const confirmMessage = options && options.confirmMessage ? options.confirmMessage : '';
            if (confirmMessage) {
                const confirmed = window.confirm(confirmMessage);
                if (!confirmed) { return; }
            }

            state.active = true;
            state.cancelled = false;
            state.hadError = false;
            state.force = force;
            if (state.pollTimer) {
                window.clearTimeout(state.pollTimer);
                state.pollTimer = null;
            }
            state.queue = [];
            state.total = 0;
            state.completed = 0;
            setTranscribeControls($wrap, true);
            $wrap.attr('aria-busy', 'true');
            setTranscribeStatus($wrap, transcribeMessages.working, false);

            state.request = $.post(ajaxUrl, {
                action: 'll_tools_get_lesson_transcribe_queue',
                nonce: editNonce,
                lesson_id: lessonId,
                mode: mode
            }).done(function (response) {
                if (state.cancelled) {
                    finishTranscribe($wrap, transcribeMessages.cancelled, false);
                    return;
                }
                if (!response || response.success !== true) {
                    finishTranscribe($wrap, transcribeMessages.error, true);
                    return;
                }
                const data = response.data || {};
                const queue = Array.isArray(data.queue) ? data.queue.slice() : [];
                const total = parseInt(data.total, 10) || queue.length;
                if (!queue.length) {
                    finishTranscribe($wrap, transcribeMessages.none, false);
                    return;
                }

                state.queue = queue;
                state.total = total;
                state.completed = 0;

                const processNext = function () {
                    if (state.cancelled) {
                        finishTranscribe($wrap, transcribeMessages.cancelled, false);
                        return;
                    }
                    if (!state.queue.length) {
                        const message = state.hadError ? transcribeMessages.error : transcribeMessages.done;
                        finishTranscribe($wrap, message, state.hadError);
                        return;
                    }

                    const next = state.queue.shift();
                    const recordingId = parseInt(next.recording_id, 10) || 0;
                    if (!recordingId) {
                        processNext();
                        return;
                    }

                    state.completed += 1;
                    setTranscribeStatus(
                        $wrap,
                        formatTranscribeProgress(transcribeMessages.progress, state.completed, state.total),
                        false
                    );

                    const requestTranscription = function (transcriptId, attempt) {
                        const payload = {
                            action: 'll_tools_transcribe_recording_by_id',
                            nonce: editNonce,
                            lesson_id: lessonId,
                            recording_id: recordingId,
                            force: state.force ? 1 : 0
                        };
                        if (transcriptId) {
                            payload.transcript_id = transcriptId;
                        }

                        state.request = $.post(ajaxUrl, payload).done(function (res) {
                            if (state.cancelled) {
                                finishTranscribe($wrap, transcribeMessages.cancelled, false);
                                return;
                            }
                            if (!res || res.success !== true) {
                                state.hadError = true;
                                processNext();
                                return;
                            }

                            const data = res.data || {};
                            if (data.pending) {
                                const nextTranscriptId = typeof data.transcript_id === 'string' ? data.transcript_id : '';
                                if (!nextTranscriptId || (attempt + 1) >= transcribePollAttempts) {
                                    state.hadError = true;
                                    processNext();
                                    return;
                                }
                                state.pollTimer = window.setTimeout(function () {
                                    state.pollTimer = null;
                                    requestTranscription(nextTranscriptId, attempt + 1);
                                }, transcribePollIntervalMs);
                                return;
                            }

                            const rec = data.recording ? data.recording : null;
                            if (rec) {
                                applyRecordingUpdate(rec);
                            }
                            const word = data.word ? data.word : null;
                            if (word) {
                                applyWordUpdate(word);
                            }
                            processNext();
                        }).fail(function () {
                            if (state.cancelled) {
                                finishTranscribe($wrap, transcribeMessages.cancelled, false);
                                return;
                            }
                            state.hadError = true;
                            processNext();
                        }).always(function () {
                            state.request = null;
                        });
                    };

                    requestTranscription('', 0);
                };

                processNext();
            }).fail(function () {
                if (state.cancelled) {
                    finishTranscribe($wrap, transcribeMessages.cancelled, false);
                    return;
                }
                finishTranscribe($wrap, transcribeMessages.error, true);
            });
        }

        function runClearCaptions($wrap, lessonId) {
            const state = getTranscribeState($wrap);
            if (state.active) { return; }
            if (transcribeMessages.confirmClear) {
                const confirmed = window.confirm(transcribeMessages.confirmClear);
                if (!confirmed) { return; }
            }

            state.active = true;
            state.cancelled = false;
            setTranscribeControls($wrap, true);
            $wrap.attr('aria-busy', 'true');
            setTranscribeStatus($wrap, transcribeMessages.clearing, false);

            state.request = $.post(ajaxUrl, {
                action: 'll_tools_clear_lesson_transcriptions',
                nonce: editNonce,
                lesson_id: lessonId
            }).done(function (response) {
                if (state.cancelled) {
                    finishTranscribe($wrap, transcribeMessages.cancelled, false);
                    return;
                }
                if (!response || response.success !== true) {
                    finishTranscribe($wrap, transcribeMessages.error, true);
                    return;
                }
                const data = response.data || {};
                const cleared = Array.isArray(data.cleared) ? data.cleared : [];
                if (cleared.length) {
                    applyClearedRecordings(cleared);
                    finishTranscribe($wrap, transcribeMessages.cleared, false);
                } else {
                    finishTranscribe($wrap, transcribeMessages.none, false);
                }
            }).fail(function () {
                if (state.cancelled) {
                    finishTranscribe($wrap, transcribeMessages.cancelled, false);
                    return;
                }
                finishTranscribe($wrap, transcribeMessages.error, true);
            });
        }

        function cancelLessonTranscription($wrap) {
            const state = getTranscribeState($wrap);
            if (!state.active) { return; }
            state.cancelled = true;
            if (state.request && typeof state.request.abort === 'function') {
                state.request.abort();
            } else {
                finishTranscribe($wrap, transcribeMessages.cancelled, false);
            }
        }

        $('[data-ll-transcribe-recordings]').on('click', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const $wrap = $btn.closest('[data-ll-transcribe-wrapper]');
            const lessonId = parseInt($btn.attr('data-lesson-id'), 10) || 0;
            if (!lessonId) { return; }
            runLessonTranscription($wrap, lessonId, {
                mode: 'missing',
                force: false,
                confirmMessage: transcribeMessages.confirm
            });
        });

        $('[data-ll-transcribe-replace]').on('click', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const $wrap = $btn.closest('[data-ll-transcribe-wrapper]');
            const lessonId = parseInt($btn.attr('data-lesson-id'), 10) || 0;
            if (!lessonId) { return; }
            runLessonTranscription($wrap, lessonId, {
                mode: 'all',
                force: true,
                confirmMessage: transcribeMessages.confirmReplace
            });
        });

        $('[data-ll-transcribe-clear]').on('click', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const $wrap = $btn.closest('[data-ll-transcribe-wrapper]');
            const lessonId = parseInt($btn.attr('data-lesson-id'), 10) || 0;
            if (!lessonId) { return; }
            runClearCaptions($wrap, lessonId);
        });

        $('[data-ll-transcribe-cancel]').on('click', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const $wrap = $btn.closest('[data-ll-transcribe-wrapper]');
            if (!$wrap.length) { return; }
            cancelLessonTranscription($wrap);
        });
    }

    updateAllStarToggles();
    updateStarModeButtons();
})(jQuery);
