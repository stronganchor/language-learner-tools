(function ($) {
    'use strict';

    const cfg = window.llToolsWordGridData || {};
    const ajaxUrl = cfg.ajaxUrl || '';
    const nonce = cfg.nonce || '';
    const i18n = cfg.i18n || {};
    const editI18n = cfg.editI18n || {};
    const transcribeI18n = cfg.transcribeI18n || {};
    const ipaSpecialChars = Array.isArray(cfg.ipaSpecialChars) ? cfg.ipaSpecialChars.slice() : [];
    const ipaCommonChars = ['t͡ʃ', 'd͡ʒ', 'ʃ', 'ˈ'];
    const isLoggedIn = !!cfg.isLoggedIn;
    const canEdit = !!cfg.canEdit;
    const editNonce = cfg.editNonce || '';
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

    const editMessages = {
        saving: editI18n.saving || 'Saving...',
        saved: editI18n.saved || 'Saved.',
        error: editI18n.error || 'Unable to save changes.'
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
        $item.find('input[data-ll-word-input], input[data-ll-recording-input]').each(function () {
            const $input = $(this);
            $input.data('original', $input.val() || '');
        });
    }

    function restoreOriginalInputs($item) {
        $item.find('input[data-ll-word-input], input[data-ll-recording-input]').each(function () {
            const $input = $(this);
            const original = $input.data('original');
            if (typeof original === 'string') {
                $input.val(original);
            }
        });
    }

    function setEditStatus($item, message, isError) {
        const $status = $item.find('[data-ll-word-edit-status]').first();
        if (!$status.length) { return; }
        $status.text(message || '');
        $status.toggleClass('is-error', !!isError);
    }

    function updateOriginalInputs($item) {
        $item.find('input[data-ll-word-input], input[data-ll-recording-input]').each(function () {
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
            const ipa = ($rec.find('[data-ll-recording-input="ipa"]').val() || '').toString();
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
                $ipa = $('<span>', { class: 'll-word-recording-ipa' }).appendTo($textWrap);
            }
            $ipa.text(parts.ipa);
        } else {
            $ipa.remove();
        }

        if (!$textWrap.children().length) {
            $textWrap.remove();
        }
    }

    const ipaAllowedChar = /[a-z\u00C0-\u02FF\u0300-\u036F\u0370-\u03FF\u1D00-\u1DFF\u{10784}\. ]/u;
    const ipaCombiningMark = /[\u0300-\u036F]/u;
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
        'B': 'ᴮ',
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
    let activeIpaInput = null;
    let activeIpaSelection = null;

    function normalizeIpaChar(ch) {
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

    function applySuperscriptToSelection(input) {
        if (!input) { return; }
        const selection = (typeof input.selectionStart === 'number' && typeof input.selectionEnd === 'number')
            ? { start: input.selectionStart, end: input.selectionEnd }
            : activeIpaSelection;
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

    function isTieBar(ch) {
        return ch === '\u0361' || ch === '\u035C';
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

    function renderIpaKeyboard($keyboard, chars) {
        if (!$keyboard || !$keyboard.length) { return 0; }
        $keyboard.empty();

        const merged = [];
        const seen = new Set();
        const pushUnique = function (ch) {
            if (!ch || seen.has(ch)) { return; }
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

    function showIpaKeyboard($input) {
        if (!$input || !$input.length) { return; }
        const $recording = $input.closest('.ll-word-edit-recording');
        const $keyboard = $recording.find('[data-ll-ipa-keyboard]').first();
        if (!$keyboard.length) { return; }
        $('[data-ll-ipa-keyboard]').attr('aria-hidden', 'true');
        $('[data-ll-ipa-superscript]').attr('aria-hidden', 'true');
        const keyCount = renderIpaKeyboard($keyboard, ipaSpecialChars);
        if (keyCount > 0) {
            $keyboard.attr('aria-hidden', 'false');
            $recording.find('[data-ll-ipa-superscript]').attr('aria-hidden', 'false');
            activeIpaInput = $input.get(0);
        } else {
            $keyboard.attr('aria-hidden', 'true');
            activeIpaInput = null;
        }
    }

    function hideIpaKeyboards() {
        $('[data-ll-ipa-keyboard]').attr('aria-hidden', 'true');
        $('[data-ll-ipa-superscript]').attr('aria-hidden', 'true');
        activeIpaInput = null;
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

        $grids.on('focus', '.ll-word-edit-input--ipa', function () {
            showIpaKeyboard($(this));
            updateIpaSelection(this);
        });

        $grids.on('click keyup mouseup', '.ll-word-edit-input--ipa', function () {
            updateIpaSelection(this);
        });

        $grids.on('input', '.ll-word-edit-input--ipa', function () {
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

        $grids.on('click', '.ll-word-ipa-key', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const ch = $(this).attr('data-ipa-char') || $(this).text();
            if (!activeIpaInput || !ch) { return; }
            insertIpaChar(activeIpaInput, ch);
            activeIpaInput.focus();
        });

        $(document).on('click.llWordGridIpa', function (e) {
            if ($(e.target).closest('.ll-word-edit-input--ipa, .ll-word-edit-ipa-keyboard, .ll-word-edit-ipa-superscript').length) {
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
            const recordings = [];

            $item.find('.ll-word-edit-recording[data-recording-id]').each(function () {
                const $rec = $(this);
                const recId = parseInt($rec.attr('data-recording-id'), 10) || 0;
                if (!recId) { return; }
                const text = ($rec.find('[data-ll-recording-input="text"]').val() || '').toString();
                const translation = ($rec.find('[data-ll-recording-input="translation"]').val() || '').toString();
                const ipa = ($rec.find('[data-ll-recording-input="ipa"]').val() || '').toString();
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
                recordings: JSON.stringify(recordings)
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

                    state.request = $.post(ajaxUrl, {
                        action: 'll_tools_transcribe_recording_by_id',
                        nonce: editNonce,
                        lesson_id: lessonId,
                        recording_id: recordingId,
                        force: state.force ? 1 : 0
                    }).done(function (res) {
                        if (state.cancelled) {
                            return;
                        }
                        if (res && res.success === true) {
                            const rec = res.data && res.data.recording ? res.data.recording : null;
                            if (rec) {
                                applyRecordingUpdate(rec);
                            }
                            const word = res.data && res.data.word ? res.data.word : null;
                            if (word) {
                                applyWordUpdate(word);
                            }
                        } else {
                            state.hadError = true;
                        }
                    }).fail(function () {
                        if (!state.cancelled) {
                            state.hadError = true;
                        }
                    }).always(function () {
                        state.request = null;
                        processNext();
                    });
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
