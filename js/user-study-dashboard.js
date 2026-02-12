(function ($) {
    'use strict';

    const cfg = window.llToolsStudyData || {};
    const payload = cfg.payload || {};
    const ajaxUrl = cfg.ajaxUrl || '';
    const nonce = cfg.nonce || '';
    const i18n = cfg.i18n || {};
    const selfCheckShared = window.LLToolsSelfCheckShared || null;

    const $root = $('[data-ll-study-root]');
    if (!$root.length) { return; }

    let state = Object.assign({ wordset_id: 0, category_ids: [], starred_word_ids: [], star_mode: 'normal', fast_transitions: false }, payload.state || {});
    let wordsets = payload.wordsets || [];
    let categories = payload.categories || [];
    let wordsByCategory = payload.words_by_category || {};
    let goals = normalizeStudyGoals(payload.goals || {});
    let categoryProgress = normalizeCategoryProgress(payload.category_progress || {});
    let nextActivity = normalizeNextActivity(payload.next_activity || null);
    let savingTimer = null;
    let goalsTimer = null;
    let genderConfig = normalizeGenderConfig(payload.gender || {});
    state.category_ids = uniqueIntList(state.category_ids || []);
    state.starred_word_ids = uniqueIntList(state.starred_word_ids || []);

    const $wordsetSelect = $root.find('[data-ll-study-wordset]');
    const $categoriesWrap = $root.find('[data-ll-study-categories]');
    const $wordsWrap = $root.find('[data-ll-study-words]');
    const $catEmpty = $root.find('[data-ll-cat-empty]');
    const $wordsEmpty = $root.find('[data-ll-words-empty]');
    const $starCount = $root.find('[data-ll-star-count]');
    const $starModeToggle = $root.find('[data-ll-star-mode]');
    const $transitionToggle = $root.find('[data-ll-transition-speed]');
    const $genderStart = $root.find('[data-ll-study-gender]');
    const $checkStart = $root.find('[data-ll-study-check-start]');
    const $placementStart = $root.find('[data-ll-study-placement-start]');
    const $checkPanel = $root.find('[data-ll-study-check]');
    const $checkPrompt = $root.find('[data-ll-study-check-prompt]');
    const $checkCategory = $root.find('[data-ll-study-check-category]');
    const $checkProgress = $root.find('[data-ll-study-check-progress]');
    const $checkCard = $root.find('[data-ll-study-check-card]');
    const $checkFlip = $root.find('[data-ll-study-check-flip]');
    const $checkAnswer = $root.find('[data-ll-study-check-answer]');
    const $checkActions = $root.find('[data-ll-study-check-actions]');
    const $checkKnow = $root.find('[data-ll-study-check-know]');
    const $checkUnknown = $root.find('[data-ll-study-check-unknown]');
    const $checkComplete = $root.find('[data-ll-study-check-complete]');
    const $checkSummary = $root.find('[data-ll-study-check-summary]');
    const $checkApply = $root.find('[data-ll-study-check-apply]');
    const $checkRestart = $root.find('[data-ll-study-check-restart]');
    const $checkExit = $root.find('[data-ll-study-check-exit]');
    const $nextText = $root.find('[data-ll-study-next-text]');
    const $startNext = $root.find('[data-ll-study-start-next]');
    const $goalsModes = $root.find('[data-ll-goals-modes]');
    const $goalDailyNew = $root.find('[data-ll-goal-daily-new]');
    const $goalMarkKnown = $root.find('[data-ll-goal-mark-known]');
    const $goalClearKnown = $root.find('[data-ll-goal-clear-known]');

    let currentAudio = null;
    let currentAudioButton = null;
    const recordingTypeOrder = ['question', 'isolation', 'introduction'];
    const recordingIcons = {
        question: '❓',
        isolation: '🔍',
        introduction: '💬'
    };
    const recordingLabels = {
        question: i18n.recordingQuestion || 'Question',
        isolation: i18n.recordingIsolation || 'Isolation',
        introduction: i18n.recordingIntroduction || 'Introduction'
    };
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
    let checkSession = null;
    let checkScrollLock = null;
    let checkScrollTouched = false;
    const PLACEMENT_WORDS_PER_CATEGORY = 4;
    const PLACEMENT_MIN_WORDS_TO_JUDGE = 3;
    const PLACEMENT_KNOWN_THRESHOLD = 0.75;

    function hasVisibleFlashcardPopup() {
        try {
            return $('#ll-tools-flashcard-popup:visible, #ll-tools-flashcard-quiz-popup:visible').length > 0;
        } catch (_) {
            const popup = document.getElementById('ll-tools-flashcard-popup');
            const quizPopup = document.getElementById('ll-tools-flashcard-quiz-popup');
            const isVisible = function (el) {
                return !!(el && (el.offsetParent !== null || el.style.display !== 'none'));
            };
            return isVisible(popup) || isVisible(quizPopup);
        }
    }

    function isFlashcardOverlayActive() {
        const body = document.body;
        if (!body) { return false; }
        const hasOverlayClass = body.classList.contains('ll-tools-flashcard-open') || body.classList.contains('ll-qpg-popup-active');
        if (!hasOverlayClass) { return false; }
        return hasVisibleFlashcardPopup();
    }

    function lockCheckViewportScroll() {
        const docEl = document.documentElement;
        const body = document.body;
        if (!docEl || !body) { return; }
        if (checkScrollLock && checkScrollLock.active) { return; }
        checkScrollTouched = true;
        checkScrollLock = {
            active: true,
            htmlOverflow: docEl.style.overflow || '',
            bodyOverflow: body.style.overflow || ''
        };
        docEl.style.overflow = 'hidden';
        body.style.overflow = 'hidden';
        docEl.classList.add('ll-study-check-open');
        body.classList.add('ll-study-check-open');
    }

    function unlockCheckViewportScroll(forceRestore) {
        const docEl = document.documentElement;
        const body = document.body;
        if (!docEl || !body || !checkScrollLock || !checkScrollLock.active) { return; }
        docEl.classList.remove('ll-study-check-open');
        body.classList.remove('ll-study-check-open');

        // Keep the saved state if another overlay is actively open; restore on close.
        if (!forceRestore && isFlashcardOverlayActive()) {
            return;
        }

        docEl.style.overflow = checkScrollLock.htmlOverflow;
        body.style.overflow = checkScrollLock.bodyOverflow;
        checkScrollLock = null;
    }

    function restoreDashboardScrollIfNeeded() {
        const docEl = document.documentElement;
        const body = document.body;
        if (!docEl || !body) { return; }
        if (isFlashcardOverlayActive()) { return; }
        if ($checkPanel.length && $checkPanel.hasClass('is-active')) { return; }

        if (checkScrollLock && checkScrollLock.active) {
            unlockCheckViewportScroll(true);
            return;
        }

        docEl.classList.remove('ll-study-check-open');
        body.classList.remove('ll-study-check-open');

        if (!checkScrollTouched) { return; }
        if (docEl.style.overflow === 'hidden') {
            docEl.style.overflow = '';
        }
        if (body.style.overflow === 'hidden') {
            body.style.overflow = '';
        }
    }

    function formatRecordingLabel(typeLabel) {
        const template = i18n.playAudioType || '';
        if (template && template.indexOf('%s') !== -1) {
            return template.replace('%s', typeLabel);
        }
        if (template) {
            return template + ' ' + typeLabel;
        }
        return 'Play ' + typeLabel + ' recording';
    }

    function canUseVisualizerForUrl(url) {
        if (!url) { return false; }
        try {
            const target = new URL(url, window.location.href);
            return target.origin === window.location.origin;
        } catch (_) {
            return false;
        }
    }

    function selectRecordingUrl(word, type) {
        const audioFiles = Array.isArray(word.audio_files) ? word.audio_files : [];
        const preferredSpeaker = parseInt(word.preferred_speaker_user_id, 10) || 0;
        let match = null;

        if (preferredSpeaker) {
            match = audioFiles.find(function (file) {
                return file && file.url && file.recording_type === type && parseInt(file.speaker_user_id, 10) === preferredSpeaker;
            });
        }

        if (!match) {
            match = audioFiles.find(function (file) {
                return file && file.url && file.recording_type === type;
            });
        }

        return match ? match.url : '';
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
            try { vizSource.disconnect(); } catch (_) { }
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

            let source = audio.__llStudyVisualizerSource;
            if (!source) {
                try {
                    source = ctx.createMediaElementSource(audio);
                    audio.__llStudyVisualizerSource = source;
                } catch (_) {
                    return;
                }
            }

            try { source.disconnect(); } catch (_) { }
            try {
                source.connect(analyser);
            } catch (_) {
                try { source.connect(ctx.destination); } catch (_) { }
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
        }).catch(function () { });
    }

    function normalizeStarMode(mode) {
        const val = (mode || '').toString();
        return (val === 'normal' || val === 'only' || val === 'weighted') ? val : 'normal';
    }

    function normalizeProgressMode(mode) {
        const val = String(mode || '').trim().toLowerCase();
        if (val === 'self_check') { return 'self-check'; }
        return ['learning', 'practice', 'listening', 'gender', 'self-check'].indexOf(val) !== -1 ? val : '';
    }

    function uniqueIntList(values) {
        const seen = {};
        return toIntList(values).filter(function (id) {
            if (seen[id]) { return false; }
            seen[id] = true;
            return true;
        });
    }

    function normalizeStudyGoals(raw) {
        const src = (raw && typeof raw === 'object') ? raw : {};
        const modesRaw = Array.isArray(src.enabled_modes) ? src.enabled_modes : ['learning', 'practice', 'listening', 'gender', 'self-check'];
        const modeSeen = {};
        let enabledModes = modesRaw.map(normalizeProgressMode).filter(function (mode) {
            if (!mode || modeSeen[mode]) { return false; }
            modeSeen[mode] = true;
            return true;
        });
        if (!enabledModes.length) {
            enabledModes = ['learning', 'practice', 'listening', 'gender', 'self-check'];
        }
        return {
            enabled_modes: enabledModes,
            ignored_category_ids: uniqueIntList(src.ignored_category_ids || []),
            preferred_wordset_ids: uniqueIntList(src.preferred_wordset_ids || []),
            placement_known_category_ids: uniqueIntList(src.placement_known_category_ids || []),
            daily_new_word_target: Math.max(0, Math.min(12, parseInt(src.daily_new_word_target, 10) || 0))
        };
    }

    function normalizeCategoryProgress(raw) {
        const src = (raw && typeof raw === 'object') ? raw : {};
        const out = {};
        Object.keys(src).forEach(function (key) {
            const id = parseInt(key, 10) || 0;
            if (!id) { return; }
            const entry = src[key];
            if (!entry || typeof entry !== 'object') { return; }
            out[id] = {
                category_id: id,
                exposure_total: Math.max(0, parseInt(entry.exposure_total, 10) || 0),
                exposure_by_mode: (entry.exposure_by_mode && typeof entry.exposure_by_mode === 'object') ? entry.exposure_by_mode : {},
                last_mode: normalizeProgressMode(entry.last_mode) || 'practice',
                last_seen_at: String(entry.last_seen_at || '')
            };
        });
        return out;
    }

    function normalizeNextActivity(raw) {
        if (!raw || typeof raw !== 'object') {
            return null;
        }
        const mode = normalizeProgressMode(raw.mode);
        if (!mode) {
            return null;
        }
        return {
            type: String(raw.type || ''),
            reason_code: String(raw.reason_code || ''),
            mode: mode,
            category_ids: uniqueIntList(raw.category_ids || []),
            session_word_ids: uniqueIntList(raw.session_word_ids || []),
            details: (raw.details && typeof raw.details === 'object') ? raw.details : {}
        };
    }

    function normalizeGenderConfig(raw) {
        const cfg = (raw && typeof raw === 'object') ? raw : {};
        const optionsRaw = Array.isArray(cfg.options) ? cfg.options : [];
        const options = [];
        const seen = {};
        optionsRaw.forEach(function (opt) {
            if (opt === null || opt === undefined) { return; }
            const val = String(opt).trim();
            if (!val) { return; }
            const key = val.toLowerCase();
            if (seen[key]) { return; }
            seen[key] = true;
            options.push(val);
        });
        return {
            enabled: !!cfg.enabled,
            options: options,
            min_count: parseInt(cfg.min_count, 10) || 0
        };
    }

    function setGenderConfig(raw) {
        genderConfig = normalizeGenderConfig(raw);
    }

    function toIntList(arr) {
        return (arr || []).map(function (v) { return parseInt(v, 10) || 0; }).filter(function (v) { return v > 0; });
    }

    function findWordsetSlug(id) {
        const ws = wordsets.find(function (w) { return parseInt(w.id, 10) === parseInt(id, 10); });
        return ws ? ws.slug : '';
    }

    function isWordStarred(id) {
        return state.starred_word_ids.indexOf(id) !== -1;
    }

    function setStarredWordIds(ids) {
        const seen = {};
        state.starred_word_ids = toIntList(ids).filter(function (id) {
            if (seen[id]) { return false; }
            seen[id] = true;
            return true;
        });
    }

    function getCategoryWords(catId) {
        return wordsByCategory[catId] || [];
    }

    function categoryStarState(catId) {
        const words = getCategoryWords(catId);
        if (!words.length) {
            return { allStarred: false, hasWords: false };
        }
        const ids = words.map(function (w) { return parseInt(w.id, 10) || 0; }).filter(Boolean);
        if (!ids.length) {
            return { allStarred: false, hasWords: false };
        }
        const allStarred = ids.every(function (id) { return isWordStarred(id); });
        return { allStarred: allStarred, hasWords: true };
    }

    function getProgressTracker() {
        return (window.LLFlashcards && window.LLFlashcards.ProgressTracker)
            ? window.LLFlashcards.ProgressTracker
            : null;
    }

    function syncProgressTrackerContext(modeOverride, categoryIdsOverride) {
        const tracker = getProgressTracker();
        if (!tracker || typeof tracker.setContext !== 'function') { return; }
        tracker.setContext({
            mode: modeOverride || 'practice',
            wordsetId: parseInt(state.wordset_id, 10) || 0,
            categoryIds: uniqueIntList(categoryIdsOverride || state.category_ids || [])
        });
    }

    function isCategoryIgnored(catId) {
        const cid = parseInt(catId, 10) || 0;
        if (!cid) { return false; }
        return goals.ignored_category_ids.indexOf(cid) !== -1;
    }

    function isCategoryKnown(catId) {
        const cid = parseInt(catId, 10) || 0;
        if (!cid) { return false; }
        return goals.placement_known_category_ids.indexOf(cid) !== -1;
    }

    function ensureSelectedCategoriesRespectGoals() {
        state.category_ids = uniqueIntList(state.category_ids).filter(function (id) {
            return !isCategoryIgnored(id);
        });
    }

    function getCategoryLabelById(catId) {
        const cid = parseInt(catId, 10) || 0;
        if (!cid) { return ''; }
        const found = categories.find(function (cat) {
            return parseInt(cat.id, 10) === cid;
        });
        if (!found) { return ''; }
        return found.translation || found.name || '';
    }

    function modeLabel(mode) {
        const key = normalizeProgressMode(mode);
        if (key === 'learning') { return i18n.modeLearning || 'Learn'; }
        if (key === 'practice') { return i18n.modePractice || 'Practice'; }
        if (key === 'listening') { return i18n.modeListening || 'Listen'; }
        if (key === 'gender') { return i18n.modeGender || 'Gender'; }
        if (key === 'self-check') { return i18n.modeSelfCheck || 'Self check'; }
        return key || (i18n.modePractice || 'Practice');
    }

    function formatTemplate(template, values) {
        const text = String(template || '');
        if (!text) { return ''; }
        const replacements = Array.isArray(values) ? values : [];
        let output = text;
        replacements.forEach(function (val, idx) {
            const oneBased = idx + 1;
            const safe = String(val === null || val === undefined ? '' : val);
            output = output.replace(new RegExp('%' + oneBased + '\\$s', 'g'), safe);
            output = output.replace(new RegExp('%' + oneBased + '\\$d', 'g'), safe);
        });
        if (replacements.length) {
            output = output.replace(/%s/g, String(replacements[0]));
            output = output.replace(/%d/g, String(replacements[0]));
        }
        return output;
    }

    function renderGoalsControls() {
        if ($goalsModes.length) {
            const enabledLookup = {};
            (goals.enabled_modes || []).forEach(function (mode) {
                enabledLookup[mode] = true;
            });
            $goalsModes.find('[data-goal-mode]').each(function () {
                const mode = normalizeProgressMode($(this).attr('data-goal-mode'));
                const active = !!enabledLookup[mode];
                $(this).toggleClass('active', active).attr('aria-pressed', active ? 'true' : 'false');
            });
        }
        if ($goalDailyNew.length) {
            $goalDailyNew.val(String(Math.max(0, Math.min(12, parseInt(goals.daily_new_word_target, 10) || 0))));
        }
        renderModeButtonsForGoals();
    }

    function isModeEnabled(mode) {
        const key = normalizeProgressMode(mode);
        if (!key) { return false; }
        return (goals.enabled_modes || []).indexOf(key) !== -1;
    }

    function renderModeButtonsForGoals() {
        // Goal mode toggles only shape recommendations, not direct launch controls.
        $root.find('[data-ll-study-start]').each(function () {
            $(this).prop('disabled', false).attr('aria-disabled', 'false');
        });
    }

    function renderNextActivity() {
        if (!$nextText.length) { return; }
        const next = normalizeNextActivity(nextActivity);
        if (!next || !next.mode || !isModeEnabled(next.mode)) {
            $nextText.text(i18n.nextNone || 'No recommendation yet. Pick categories or do one round first.');
            if ($startNext.length) {
                $startNext.prop('disabled', true).addClass('disabled');
            }
            return;
        }

        const labels = uniqueIntList(next.category_ids).map(getCategoryLabelById).filter(Boolean);
        const categoryText = labels.length ? labels.join(', ') : (i18n.categoriesLabel || 'Categories');
        const wordCount = uniqueIntList(next.session_word_ids).length;
        const modeText = modeLabel(next.mode);
        const message = (wordCount > 0)
            ? formatTemplate(i18n.nextReady || 'Recommended: %1$s in %2$s (%3$d words).', [modeText, categoryText, wordCount])
            : formatTemplate(i18n.nextReadyNoCount || 'Recommended: %1$s in %2$s.', [modeText, categoryText]);
        $nextText.text(message);
        if ($startNext.length) {
            $startNext.prop('disabled', false).removeClass('disabled');
        }
    }

    function applyNextActivity(next) {
        nextActivity = normalizeNextActivity(next);
        renderNextActivity();
    }

    function saveGoalsDebounced() {
        clearTimeout(goalsTimer);
        goalsTimer = setTimeout(function () {
            saveGoalsNow();
        }, 300);
    }

    function saveGoalsNow() {
        goals = normalizeStudyGoals(goals);
        ensureSelectedCategoriesRespectGoals();
        renderGoalsControls();
        renderCategories();
        renderWords();

        return $.post(ajaxUrl, {
            action: 'll_user_study_save_goals',
            nonce: nonce,
            wordset_id: state.wordset_id,
            category_ids: state.category_ids,
            goals: JSON.stringify(goals)
        }).done(function (res) {
            if (res && res.success && res.data) {
                if (res.data.goals) {
                    goals = normalizeStudyGoals(res.data.goals);
                    ensureSelectedCategoriesRespectGoals();
                    renderGoalsControls();
                    renderCategories();
                    renderWords();
                }
                if (Object.prototype.hasOwnProperty.call(res.data, 'next_activity')) {
                    applyNextActivity(res.data.next_activity);
                }
            }
        });
    }

    function refreshRecommendation() {
        return $.post(ajaxUrl, {
            action: 'll_user_study_recommendation',
            nonce: nonce,
            wordset_id: state.wordset_id,
            category_ids: state.category_ids
        }).done(function (res) {
            if (res && res.success && res.data && Object.prototype.hasOwnProperty.call(res.data, 'next_activity')) {
                applyNextActivity(res.data.next_activity);
            }
        });
    }

    function getGenderOptions() {
        return Array.isArray(genderConfig.options) ? genderConfig.options : [];
    }

    function isGenderEnabledForWordset() {
        return !!genderConfig.enabled && getGenderOptions().length >= 2;
    }

    function isGenderSupportedForSelection(categoryIds) {
        if (!isGenderEnabledForWordset()) { return false; }
        const selectedIds = toIntList(Array.isArray(categoryIds) ? categoryIds : state.category_ids);
        if (!selectedIds.length) { return false; }
        const selectedCats = categories.filter(function (cat) {
            return selectedIds.indexOf(parseInt(cat.id, 10)) !== -1;
        });
        if (!selectedCats.length) { return false; }
        return selectedCats.some(function (cat) {
            return !!(cat && cat.gender_supported);
        });
    }

    function updateGenderButtonVisibility() {
        if (!$genderStart.length) { return; }
        const allowed = isGenderSupportedForSelection();
        $genderStart.toggleClass('ll-study-btn--hidden', !allowed);
        $genderStart.attr('aria-hidden', allowed ? 'false' : 'true');
    }

    function applyGenderConfigToFlashcardsData(flashData) {
        const target = flashData || (window.llToolsFlashcardsData || {});
        const options = getGenderOptions();
        target.genderEnabled = !!genderConfig.enabled;
        target.genderOptions = options.slice();
        target.genderWordsetId = parseInt(state.wordset_id, 10) || 0;
        const fallbackMin = parseInt(target.genderMinCount, 10) || 0;
        const minCount = parseInt(genderConfig.min_count, 10) || fallbackMin || 2;
        target.genderMinCount = minCount;
    }

    function ensureWordsForCategory(catId) {
        const cid = parseInt(catId, 10);
        if (!cid) { return $.Deferred().resolve([]).promise(); }
        if (wordsByCategory[cid]) {
            return $.Deferred().resolve(wordsByCategory[cid]).promise();
        }
        return $.post(ajaxUrl, {
            action: 'll_user_study_fetch_words',
            nonce: nonce,
            wordset_id: state.wordset_id,
            category_ids: [cid]
        }).then(function (res) {
            if (res && res.success && res.data && res.data.words_by_category) {
                wordsByCategory = Object.assign({}, wordsByCategory, res.data.words_by_category);
                return wordsByCategory[cid] || [];
            }
            return [];
        }, function () {
            return [];
        });
    }

    function getCategoryById(catId) {
        const cid = parseInt(catId, 10);
        if (!cid) { return null; }
        return categories.find(function (cat) {
            return parseInt(cat.id, 10) === cid;
        }) || null;
    }

    function getCategoryLabel(cat) {
        if (!cat) { return ''; }
        return cat.translation || cat.name || '';
    }

    function getCategoryOptionType(cat) {
        if (!cat) { return 'image'; }
        return cat.option_type || cat.mode || 'image';
    }

    function getCategoryPromptType(cat) {
        if (!cat) { return 'audio'; }
        return cat.prompt_type || 'audio';
    }

    function isTextOptionType(optionType) {
        return (optionType === 'text' || optionType === 'text_title' || optionType === 'text_translation' || optionType === 'text_audio');
    }

    function isAudioOptionType(optionType) {
        return (optionType === 'audio' || optionType === 'text_audio');
    }

    function getWordPrimaryText(word) {
        if (!word) { return ''; }
        const title = (typeof word.title === 'string') ? word.title.trim() : '';
        if (title) { return title; }
        return (word.label || '').toString();
    }

    function getWordTranslationText(word) {
        if (!word) { return ''; }
        const translation = (typeof word.translation === 'string') ? word.translation.trim() : '';
        if (!translation) { return ''; }
        const primary = getWordPrimaryText(word).trim().toLowerCase();
        if (primary && translation.toLowerCase() === primary) {
            return '';
        }
        return translation;
    }

    function getCheckWordLabel(word) {
        if (!word) { return ''; }
        return word.label || word.title || '';
    }

    function getWordAudioUrl(word) {
        if (!word) { return ''; }
        if (word.audio) { return word.audio; }
        const audioFiles = Array.isArray(word.audio_files) ? word.audio_files : [];
        if (!audioFiles.length) { return ''; }
        const preferred = parseInt(word.preferred_speaker_user_id, 10) || 0;
        if (preferred) {
            const match = audioFiles.find(function (file) {
                return file && file.url && parseInt(file.speaker_user_id, 10) === preferred;
            });
            if (match) { return match.url; }
        }
        const fallback = audioFiles.find(function (file) { return file && file.url; });
        return fallback ? fallback.url : '';
    }

    function formatCheckSummary(count) {
        const template = i18n.checkSummary || '';
        if (template.indexOf('%1$d') !== -1) {
            return template.replace('%1$d', count);
        }
        if (template.indexOf('%d') !== -1) {
            return template.replace('%d', count);
        }
        if (template.indexOf('%s') !== -1) {
            return template.replace('%s', count);
        }
        if (template) {
            return template + ' ' + count;
        }
        return 'You marked ' + count + ' as "I don\'t know".';
    }

    function ensureWordsForCategories(catIds) {
        const ids = toIntList(catIds);
        const requests = ids.map(function (id) { return ensureWordsForCategory(id); });
        if (!requests.length) {
            return $.Deferred().resolve().promise();
        }
        return $.when.apply($, requests).then(function () {
            return true;
        });
    }

    function shuffleItems(items) {
        const list = items.slice();
        for (let i = list.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            const temp = list[i];
            list[i] = list[j];
            list[j] = temp;
        }
        return list;
    }

    function buildCheckQueue(categoryIds) {
        const ids = shuffleItems(toIntList(categoryIds));
        const items = [];
        const seen = {};
        ids.forEach(function (cid) {
            const cat = getCategoryById(cid);
            const optionType = getCategoryOptionType(cat);
            const promptType = getCategoryPromptType(cat);
            const catLabel = getCategoryLabel(cat);
            const words = shuffleItems(getCategoryWords(cid) || []);
            words.forEach(function (word) {
                const wordId = parseInt(word.id, 10) || 0;
                if (!wordId || seen[wordId]) { return; }
                seen[wordId] = true;
                items.push({
                    word: word,
                    wordId: wordId,
                    categoryId: cid,
                    categoryLabel: catLabel,
                    optionType: optionType,
                    promptType: promptType
                });
            });
        });
        return items;
    }

    function normalizeCheckMode(mode) {
        const key = String(mode || '').toLowerCase();
        return key === 'placement' ? 'placement' : 'self-check';
    }

    function isPlacementSession() {
        return !!(checkSession && checkSession.mode === 'placement');
    }

    function getTrackerModeForSession() {
        return 'self-check';
    }

    function buildPlacementQueue(categoryIds) {
        const ids = shuffleItems(toIntList(categoryIds));
        const items = [];
        const seen = {};
        ids.forEach(function (cid) {
            const cat = getCategoryById(cid);
            const optionType = getCategoryOptionType(cat);
            const promptType = getCategoryPromptType(cat);
            const catLabel = getCategoryLabel(cat);
            const words = shuffleItems(getCategoryWords(cid) || []).slice(0, PLACEMENT_WORDS_PER_CATEGORY);
            words.forEach(function (word) {
                const wordId = parseInt(word && word.id, 10) || 0;
                if (!wordId || seen[wordId]) { return; }
                seen[wordId] = true;
                items.push({
                    word: word,
                    wordId: wordId,
                    categoryId: cid,
                    categoryLabel: catLabel,
                    optionType: optionType,
                    promptType: promptType
                });
            });
        });
        return items;
    }

    function updateCheckActionLabels(mode) {
        const normalized = normalizeCheckMode(mode);
        if ($checkApply.length) {
            $checkApply.text(normalized === 'placement'
                ? (i18n.placementApply || 'Save placement')
                : (i18n.checkApply || 'Set stars'));
        }
        if ($checkRestart.length) {
            $checkRestart.text(normalized === 'placement'
                ? (i18n.placementRestart || 'Retake placement')
                : (i18n.checkRestart || 'Review again'));
        }
    }

    function getPlacementResultsFromSession(session) {
        const src = session || {};
        const stats = (src.categoryStats && typeof src.categoryStats === 'object') ? src.categoryStats : {};
        const categoryIds = Object.keys(stats).map(function (key) { return parseInt(key, 10) || 0; }).filter(Boolean);
        const knownCategoryIds = [];
        categoryIds.forEach(function (cid) {
            const row = stats[cid] || {};
            const known = Math.max(0, parseInt(row.known, 10) || 0);
            const total = Math.max(0, parseInt(row.total, 10) || 0);
            if (total < PLACEMENT_MIN_WORDS_TO_JUDGE) { return; }
            if ((known / total) >= PLACEMENT_KNOWN_THRESHOLD) {
                knownCategoryIds.push(cid);
            }
        });
        return {
            testedCategoryIds: uniqueIntList(categoryIds),
            knownCategoryIds: uniqueIntList(knownCategoryIds)
        };
    }

    function buildCheckAudioButton(audioUrl) {
        const label = i18n.playAudio || 'Play audio';
        if (selfCheckShared && typeof selfCheckShared.buildAudioButton === 'function') {
            return selfCheckShared.buildAudioButton({
                audioUrl: audioUrl,
                label: label
            });
        }
        const $btn = $('<button>', {
            type: 'button',
            class: 'll-study-recording-btn ll-study-recording-btn--prompt',
            'data-audio-url': audioUrl,
            'data-recording-type': 'prompt',
            'aria-label': label,
            title: label
        });
        $('<span>', {
            class: 'll-study-recording-icon',
            'aria-hidden': 'true',
            'data-emoji': '\u25B6'
        }).appendTo($btn);
        const viz = $('<span>', {
            class: 'll-study-recording-visualizer',
            'aria-hidden': 'true'
        });
        for (let i = 0; i < 6; i++) {
            $('<span>', { class: 'bar' }).appendTo(viz);
        }
        $btn.append(viz);
        return $btn;
    }

    function renderCheckDisplay($container, displayType, word) {
        if (!$container || !$container.length) { return false; }
        const mode = String(displayType || '');
        if (selfCheckShared && typeof selfCheckShared.renderPromptDisplay === 'function') {
            return !!selfCheckShared.renderPromptDisplay($container, mode, word, {
                emptyLabel: i18n.checkEmpty || 'No words available for this check.',
                playAudioLabel: i18n.playAudio || 'Play audio'
            });
        }
        $container.empty();

        const showImage = mode === 'image';
        const showText = isTextOptionType(mode);
        const showAudio = isAudioOptionType(mode);
        const text = getCheckWordLabel(word);
        const audioUrl = showAudio ? getWordAudioUrl(word) : '';

        const $inner = $('<div>', { class: 'll-study-check-prompt-inner' });
        let hasContent = false;

        if (showImage && word && word.image) {
            const $imgWrap = $('<div>', { class: 'll-study-check-image' });
            $('<img>', {
                src: word.image,
                alt: text || word.title || '',
                loading: 'lazy'
            }).appendTo($imgWrap);
            $inner.append($imgWrap);
            hasContent = true;
        }

        if (showText && text) {
            $('<div>', { class: 'll-study-check-text', text: text }).appendTo($inner);
            hasContent = true;
        }

        if (showAudio && audioUrl) {
            $inner.append(buildCheckAudioButton(audioUrl));
            hasContent = true;
        }

        if (!$inner.children().length) {
            if (text) {
                $('<div>', { class: 'll-study-check-text', text: text }).appendTo($inner);
                hasContent = true;
            } else {
                $('<div>', { class: 'll-study-check-empty', text: i18n.checkEmpty || 'No words available for this check.' }).appendTo($inner);
            }
        }

        $container.append($inner);
        return hasContent;
    }

    function renderCheckPrompt(item) {
        const word = (item && item.word) ? item.word : null;
        const optionType = item ? String(item.optionType || '') : '';
        renderCheckDisplay($checkPrompt, optionType, word);
    }

    function renderCheckAnswer(item) {
        const word = (item && item.word) ? item.word : null;
        const promptType = item ? String(item.promptType || '') : '';
        return renderCheckDisplay($checkAnswer, promptType, word);
    }

    function setCheckFlipped(isFlipped) {
        if (!$checkCard.length) { return; }
        $checkCard.toggleClass('is-flipped', !!isFlipped);
    }

    function openCheckPanel() {
        if (!$checkPanel.length) { return; }
        $checkPanel.addClass('is-active').attr('aria-hidden', 'false');
        lockCheckViewportScroll();
        $checkSummary.text('');
        $checkPrompt.show().empty();
        $checkActions.show();
        if ($checkCard.length) {
            $checkCard.show();
        }
        if ($checkAnswer.length) {
            $checkAnswer.show().empty();
        }
        setCheckFlipped(false);
        $checkComplete.hide();
    }

    function closeCheckPanel() {
        if (!$checkPanel.length) { return; }
        $checkPanel.removeClass('is-active').attr('aria-hidden', 'true');
        unlockCheckViewportScroll();
        updateCheckActionLabels('self-check');
        $checkCategory.text('');
        $checkProgress.text('');
        $checkPrompt.empty().show();
        $checkActions.show();
        if ($checkCard.length) {
            $checkCard.show();
        }
        if ($checkAnswer.length) {
            $checkAnswer.show().empty();
        }
        setCheckFlipped(false);
        $checkComplete.hide();
        checkSession = null;
        stopCurrentAudio();
    }

    function showCheckItem() {
        if (!checkSession || !Array.isArray(checkSession.items) || !checkSession.items.length) {
            return;
        }
        if (checkSession.index >= checkSession.items.length) {
            showCheckComplete();
            return;
        }

        const item = checkSession.items[checkSession.index];
        const total = checkSession.items.length;
        const progressText = (checkSession.index + 1) + ' / ' + total;
        $checkProgress.text(progressText);

        const catLabel = item.categoryLabel || '';
        if (catLabel) {
            $checkCategory.text(catLabel).show();
        } else {
            $checkCategory.text('').hide();
        }

        $checkPrompt.show();
        $checkActions.show();
        if ($checkCard.length) {
            $checkCard.show();
        }
        $checkComplete.hide();

        try {
            const tracker = getProgressTracker();
            if (tracker && typeof tracker.trackWordExposure === 'function' && item.wordId) {
                const placement = isPlacementSession();
                tracker.trackWordExposure({
                    mode: getTrackerModeForSession(),
                    wordId: item.wordId,
                    categoryId: item.categoryId,
                    categoryName: item.categoryLabel || '',
                    wordsetId: state.wordset_id,
                    payload: placement ? { placement: true } : {}
                });
            }
        } catch (_) { /* no-op */ }

        renderCheckPrompt(item);
        const hasAnswer = renderCheckAnswer(item);
        if ($checkFlip.length) {
            $checkFlip.toggle(!!hasAnswer);
        }
        setCheckFlipped(false);
    }

    function showCheckComplete() {
        if (!checkSession) { return; }
        const total = checkSession.items.length || 0;
        const unknownCount = (checkSession.unknownIds || []).length;
        if (isPlacementSession()) {
            const placement = getPlacementResultsFromSession(checkSession);
            const knownCount = placement.knownCategoryIds.length;
            const testedCount = placement.testedCategoryIds.length;
            if (knownCount > 0) {
                $checkSummary.text(formatTemplate(
                    i18n.placementSummary || 'Placement: %1$d/%2$d categories marked as known.',
                    [knownCount, testedCount]
                ));
            } else {
                $checkSummary.text(i18n.placementSummaryNone || 'Placement complete. No categories were marked as known yet.');
            }
        } else {
            $checkSummary.text(formatCheckSummary(unknownCount));
        }
        if (total) {
            $checkProgress.text(total + ' / ' + total);
        }
        $checkCategory.text('').hide();
        $checkPrompt.hide().empty();
        $checkActions.hide();
        if ($checkCard.length) {
            $checkCard.hide();
        }
        if ($checkAnswer.length) {
            $checkAnswer.hide().empty();
        }
        setCheckFlipped(false);
        $checkComplete.show();
    }

    function recordCheckResult(known) {
        if (!checkSession || !checkSession.items || !checkSession.items.length) { return; }
        const item = checkSession.items[checkSession.index];
        if (!item || !item.wordId) { return; }
        try {
            const tracker = getProgressTracker();
            if (tracker && typeof tracker.trackWordOutcome === 'function') {
                const placement = isPlacementSession();
                tracker.trackWordOutcome({
                    mode: getTrackerModeForSession(),
                    wordId: item.wordId,
                    categoryId: item.categoryId,
                    categoryName: item.categoryLabel || '',
                    wordsetId: state.wordset_id,
                    isCorrect: !!known,
                    hadWrongBefore: !known,
                    payload: placement ? { placement: true } : {}
                });
            }
        } catch (_) { /* no-op */ }
        if (isPlacementSession()) {
            checkSession.categoryStats = (checkSession.categoryStats && typeof checkSession.categoryStats === 'object')
                ? checkSession.categoryStats
                : {};
            const categoryId = parseInt(item.categoryId, 10) || 0;
            if (categoryId) {
                if (!checkSession.categoryStats[categoryId]) {
                    checkSession.categoryStats[categoryId] = { known: 0, unknown: 0, total: 0 };
                }
                checkSession.categoryStats[categoryId].total += 1;
                if (known) {
                    checkSession.categoryStats[categoryId].known += 1;
                } else {
                    checkSession.categoryStats[categoryId].unknown += 1;
                }
            }
        }
        if (!known) {
            checkSession.unknownLookup = checkSession.unknownLookup || {};
            if (!checkSession.unknownLookup[item.wordId]) {
                checkSession.unknownLookup[item.wordId] = true;
                checkSession.unknownIds.push(item.wordId);
            }
        }
        checkSession.index += 1;
        stopCurrentAudio();
        showCheckItem();
    }

    function getWordIdsForCategories(catIds) {
        const ids = [];
        const seen = {};
        toIntList(catIds).forEach(function (cid) {
            const words = getCategoryWords(cid) || [];
            words.forEach(function (word) {
                const wordId = parseInt(word.id, 10) || 0;
                if (!wordId || seen[wordId]) { return; }
                seen[wordId] = true;
                ids.push(wordId);
            });
        });
        return ids;
    }

    function startCheckFlow(categoryIds, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const sessionMode = normalizeCheckMode(opts.mode || 'self-check');
        const ids = toIntList(categoryIds || state.category_ids).filter(function (id) {
            return !isCategoryIgnored(id);
        });
        if (!ids.length) {
            ensureCategoriesSelected();
            return;
        }
        syncProgressTrackerContext(getTrackerModeForSession(), ids);
        updateCheckActionLabels(sessionMode);
        if (sessionMode === 'placement') {
            if ($placementStart.length) {
                $placementStart.prop('disabled', true).addClass('loading');
            }
        } else if ($checkStart.length) {
            $checkStart.prop('disabled', true).addClass('loading');
        }

        ensureWordsForCategories(ids).always(function () {
            const items = (sessionMode === 'placement')
                ? buildPlacementQueue(ids)
                : buildCheckQueue(ids);
            if (!items.length) {
                alert(i18n.checkEmpty || 'No words available for this check.');
                closeCheckPanel();
                return;
            }
            checkSession = {
                mode: sessionMode,
                items: items,
                index: 0,
                unknownIds: [],
                unknownLookup: {},
                categoryIds: ids,
                categoryStats: {}
            };
            openCheckPanel();
            showCheckItem();
        }).always(function () {
            if (sessionMode === 'placement') {
                if ($placementStart.length) {
                    $placementStart.prop('disabled', false).removeClass('loading');
                }
            } else if ($checkStart.length) {
                $checkStart.prop('disabled', false).removeClass('loading');
            }
        });
    }

    function applyCheckStars() {
        if (!checkSession) { return; }
        const selectedCategoryLookup = {};
        toIntList(state.category_ids).forEach(function (id) {
            selectedCategoryLookup[id] = true;
        });

        const categoryIds = toIntList(checkSession.categoryIds || []).filter(function (id) {
            return !!selectedCategoryLookup[id];
        });
        if (!categoryIds.length) {
            closeCheckPanel();
            return;
        }
        const selectedWordIds = getWordIdsForCategories(categoryIds);
        if (!selectedWordIds.length) {
            closeCheckPanel();
            return;
        }
        const selectedLookup = {};
        selectedWordIds.forEach(function (id) { selectedLookup[id] = true; });

        const keep = (state.starred_word_ids || []).filter(function (id) {
            return !selectedLookup[id];
        });
        const unknownIds = toIntList(checkSession.unknownIds || []);
        const next = keep.slice();
        unknownIds.forEach(function (id) {
            if (!selectedLookup[id]) { return; }
            if (next.indexOf(id) === -1) { next.push(id); }
        });
        setStarredWordIds(next);
        setStudyPrefsGlobal();
        saveStateDebounced();
        renderWords();
        renderCategories();
        closeCheckPanel();
    }

    function applyPlacementResults() {
        if (!checkSession || !isPlacementSession()) {
            closeCheckPanel();
            return;
        }
        const placement = getPlacementResultsFromSession(checkSession);
        const testedLookup = {};
        placement.testedCategoryIds.forEach(function (id) {
            testedLookup[id] = true;
        });
        const keepExisting = uniqueIntList(goals.placement_known_category_ids || []).filter(function (id) {
            return !testedLookup[id];
        });
        goals.placement_known_category_ids = uniqueIntList(keepExisting.concat(placement.knownCategoryIds));
        renderCategories();
        renderGoalsControls();
        renderNextActivity();
        saveGoalsNow().always(function () {
            refreshRecommendation();
            closeCheckPanel();
        });
    }

    function startPlacementFlow(categoryIds) {
        const explicit = toIntList(categoryIds || []);
        let ids = explicit.length ? explicit : toIntList(state.category_ids || []);
        if (!ids.length) {
            ids = toIntList(categories.map(function (cat) { return parseInt(cat.id, 10) || 0; }));
        }
        ids = uniqueIntList(ids).filter(function (id) {
            return !isCategoryIgnored(id);
        });
        if (!ids.length) {
            ensureCategoriesSelected();
            return;
        }
        startCheckFlow(ids, { mode: 'placement' });
    }

    function setStudyPrefsGlobal() {
        state.star_mode = normalizeStarMode(state.star_mode);
        window.llToolsStudyPrefs = {
            starredWordIds: state.starred_word_ids ? state.starred_word_ids.slice() : [],
            starMode: state.star_mode || 'normal',
            star_mode: state.star_mode || 'normal',
            fastTransitions: !!state.fast_transitions,
            fast_transitions: !!state.fast_transitions
        };
    }

    function renderWordsets() {
        $wordsetSelect.empty();
        wordsets.forEach(function (ws) {
            $('<option>', {
                value: ws.id,
                text: ws.name,
                selected: parseInt(ws.id, 10) === parseInt(state.wordset_id, 10)
            }).appendTo($wordsetSelect);
        });
    }

    function renderStarModeToggle() {
        const mode = normalizeStarMode(state.star_mode);
        $starModeToggle.find('.ll-study-btn').removeClass('active');
        $starModeToggle.find('[data-mode="' + mode + '"]').addClass('active');
    }

    function renderTransitionToggle() {
        const fast = !!state.fast_transitions;
        $transitionToggle.find('.ll-study-btn').removeClass('active');
        $transitionToggle.find(fast ? '[data-speed="fast"]' : '[data-speed="slow"]').addClass('active');
    }

    function renderCategories() {
        $categoriesWrap.empty();
        ensureSelectedCategoriesRespectGoals();
        const selectedLookup = {};
        state.category_ids.forEach(function (id) { selectedLookup[id] = true; });

        if (!categories.length) {
            $catEmpty.show();
            updateGenderButtonVisibility();
            return;
        }
        $catEmpty.hide();

        categories.forEach(function (cat) {
            const catId = parseInt(cat.id, 10) || 0;
            if (!catId) { return; }
            const ignored = isCategoryIgnored(catId);
            const known = isCategoryKnown(catId);
            const checked = !ignored && !!selectedLookup[catId];
            const label = cat.translation || cat.name;
            const countLabel = typeof cat.word_count !== 'undefined' ? ' (' + cat.word_count + ')' : '';
            const progress = categoryProgress[catId] || null;
            const exposures = progress ? (parseInt(progress.exposure_total, 10) || 0) : 0;
            const row = $('<div>', {
                class: 'll-cat-row' + (ignored ? ' is-skipped' : '') + (known ? ' is-known' : ''),
                'data-cat-id': catId
            });

            const labelWrap = $('<label>', { class: 'll-cat-label' });
            $('<input>', { type: 'checkbox', value: catId, checked: checked, disabled: ignored }).appendTo(labelWrap);
            $('<span>', { class: 'll-cat-name', text: label + countLabel }).appendTo(labelWrap);
            if (exposures > 0) {
                $('<span>', { class: 'll-cat-progress-pill', text: '×' + exposures }).appendTo(labelWrap);
            }
            row.append(labelWrap);

            const actions = $('<div>', { class: 'll-cat-actions' });
            $('<button>', {
                type: 'button',
                class: 'll-study-btn tiny ghost ll-cat-action ll-cat-action--skip' + (ignored ? ' active' : ''),
                'data-cat-id': catId,
                'data-cat-action': 'skip',
                text: ignored ? (i18n.categoryUnskip || 'Use') : (i18n.categorySkip || 'Skip')
            }).appendTo(actions);
            $('<button>', {
                type: 'button',
                class: 'll-study-btn tiny ghost ll-cat-action ll-cat-action--known' + (known ? ' active' : ''),
                'data-cat-id': catId,
                'data-cat-action': 'known',
                text: known ? (i18n.categoryUnknown || 'Unknown') : (i18n.categoryKnown || 'Known')
            }).appendTo(actions);
            row.append(actions);

            $categoriesWrap.append(row);
        });
        updateGenderButtonVisibility();
    }

    function renderWords() {
        $wordsWrap.empty();
        ensureSelectedCategoriesRespectGoals();
        const selected = toIntList(state.category_ids);
        if (!selected.length) {
            $wordsEmpty.show();
            $starCount.text(0);
            return;
        }
        $wordsEmpty.hide();

        const starredLookup = {};
        state.starred_word_ids.forEach(function (id) { starredLookup[id] = true; });

        let totalStarredInView = 0;

        selected.forEach(function (cid) {
            const cat = categories.find(function (c) { return parseInt(c.id, 10) === cid; });
            const catLabel = cat ? (cat.translation || cat.name) : '';
            const words = wordsByCategory[cid] || [];

            const group = $('<div>', { class: 'll-word-group' });
            const titleRow = $('<div>', { class: 'll-word-group__title' });
            $('<span>', { text: catLabel }).appendTo(titleRow);
            const starState = categoryStarState(cid);
            const starLabel = starState.allStarred ? (i18n.unstarAll || 'Unstar all') : (i18n.starAll || 'Star all');
            $('<button>', {
                type: 'button',
                class: 'll-study-btn tiny ghost ll-group-star' + (starState.allStarred ? ' active' : ''),
                'data-cat-id': cid,
                disabled: !starState.hasWords,
                text: (starState.allStarred ? '★ ' : '☆ ') + starLabel
            }).appendTo(titleRow);
            group.append(titleRow);

            if (!words.length) {
                $('<p>', { class: 'll-word-empty', text: i18n.noWords || 'No words yet.' }).appendTo(group);
            } else {
                const list = $('<div>', { class: 'll-word-list' });
                words.forEach(function (w) {
                    const isStarred = !!starredLookup[w.id];
                    if (isStarred) { totalStarredInView++; }
                    const wordTitle = getWordPrimaryText(w);
                    const wordTranslation = getWordTranslationText(w);
                    const row = $('<div>', {
                        class: 'll-word-row' + (w.image ? '' : ' ll-word-row--no-image'),
                        'data-word-id': w.id
                    });
                    $('<button>', {
                        type: 'button',
                        class: 'll-word-star' + (isStarred ? ' active' : ''),
                        'aria-pressed': isStarred ? 'true' : 'false',
                        text: isStarred ? '★' : '☆'
                    }).appendTo(row);

                    if (w.image) {
                        const thumb = $('<div>', { class: 'll-word-thumb' });
                        $('<img>', {
                            src: w.image,
                            alt: wordTitle || wordTranslation || '',
                            loading: 'lazy'
                        }).appendTo(thumb);
                        row.append(thumb);
                    }

                    const textWrap = $('<div>', { class: 'll-word-text-wrap' });
                    $('<span>', { class: 'll-word-text', text: wordTitle }).appendTo(textWrap);
                    $('<span>', { class: 'll-word-translation', text: wordTranslation }).appendTo(textWrap);
                    row.append(textWrap);

                    const recordingsWrap = $('<div>', {
                        class: 'll-word-recordings',
                        'aria-label': i18n.recordingsLabel || 'Recordings'
                    });
                    let hasRecordings = false;
                    recordingTypeOrder.forEach(function (type) {
                        const icon = recordingIcons[type];
                        if (!icon) { return; }
                        const url = selectRecordingUrl(w, type);
                        if (!url) { return; }
                        hasRecordings = true;
                        const label = recordingLabels[type] || type;
                        const playLabel = formatRecordingLabel(label);
                        const btn = $('<button>', {
                            type: 'button',
                            class: 'll-study-recording-btn ll-study-recording-btn--' + type,
                            'data-audio-url': url,
                            'data-recording-type': type,
                            'aria-label': playLabel,
                            title: playLabel
                        });
                        $('<span>', {
                            class: 'll-study-recording-icon',
                            'aria-hidden': 'true',
                            'data-emoji': icon
                        }).appendTo(btn);
                        const viz = $('<span>', {
                            class: 'll-study-recording-visualizer',
                            'aria-hidden': 'true'
                        });
                        for (let i = 0; i < 6; i++) {
                            $('<span>', { class: 'bar' }).appendTo(viz);
                        }
                        btn.append(viz);
                        recordingsWrap.append(btn);
                    });

                    if (hasRecordings) {
                        row.append(recordingsWrap);
                    }

                    list.append(row);
                });
                group.append(list);
            }
            $wordsWrap.append(group);
        });

        $starCount.text(totalStarredInView);
    }

    function saveStateDebounced() {
        clearTimeout(savingTimer);
        savingTimer = setTimeout(function () {
            $.post(ajaxUrl, {
                action: 'll_user_study_save',
                nonce: nonce,
                wordset_id: state.wordset_id,
                category_ids: state.category_ids,
                starred_word_ids: state.starred_word_ids,
                star_mode: normalizeStarMode(state.star_mode),
                fast_transitions: state.fast_transitions ? 1 : 0
            }).done(function (res) {
                if (res && res.success && res.data) {
                    if (res.data.state) {
                        state = Object.assign({}, state, res.data.state);
                        state.category_ids = uniqueIntList(state.category_ids || []);
                        state.starred_word_ids = uniqueIntList(state.starred_word_ids || []);
                        state.star_mode = normalizeStarMode(state.star_mode);
                    }
                    if (Object.prototype.hasOwnProperty.call(res.data, 'next_activity')) {
                        applyNextActivity(res.data.next_activity);
                    }
                    syncProgressTrackerContext();
                }
            });
        }, 300);
    }

    function refreshWordsFromServer() {
        const ids = toIntList(state.category_ids);
        if (!ids.length) {
            wordsByCategory = {};
            renderWords();
            refreshRecommendation();
            return;
        }
        $.post(ajaxUrl, {
            action: 'll_user_study_fetch_words',
            nonce: nonce,
            wordset_id: state.wordset_id,
            category_ids: ids
        }).done(function (res) {
            if (res && res.success && res.data && res.data.words_by_category) {
                wordsByCategory = res.data.words_by_category;
                renderWords();
            }
            refreshRecommendation();
        });
    }

    function reloadForWordset(wordsetId) {
        $.post(ajaxUrl, {
            action: 'll_user_study_bootstrap',
            nonce: nonce,
            wordset_id: wordsetId
        }).done(function (res) {
            if (!res || !res.success || !res.data) { return; }
            const data = res.data;
            wordsets = data.wordsets || wordsets;
            categories = data.categories || [];
            setGenderConfig(data.gender || {});
            state = Object.assign({ wordset_id: wordsetId, category_ids: [], starred_word_ids: [], star_mode: 'normal', fast_transitions: false }, data.state || {});
            state.star_mode = normalizeStarMode(state.star_mode);
            state.category_ids = uniqueIntList(state.category_ids || []);
            state.starred_word_ids = uniqueIntList(state.starred_word_ids || []);
            goals = normalizeStudyGoals(data.goals || goals);
            categoryProgress = normalizeCategoryProgress(data.category_progress || categoryProgress);
            applyNextActivity(data.next_activity || null);
            wordsByCategory = data.words_by_category || {};
            ensureSelectedCategoriesRespectGoals();
            renderWordsets();
            renderGoalsControls();
            renderCategories();
            renderWords();
            setStudyPrefsGlobal();
            renderStarModeToggle();
            renderTransitionToggle();
            syncProgressTrackerContext();
        });
    }

    function ensureCategoriesSelected(categoryIds) {
        const ids = uniqueIntList(Array.isArray(categoryIds) ? categoryIds : state.category_ids);
        if (ids.length) {
            return true;
        }
        alert(i18n.noCategories || 'Pick at least one category.');
        return false;
    }

    function startFlashcards(mode, launchOptions) {
        const options = (launchOptions && typeof launchOptions === 'object') ? launchOptions : {};
        const requestedCategoryIds = uniqueIntList(options.category_ids || options.categoryIds || state.category_ids || []).filter(function (id) {
            return !isCategoryIgnored(id);
        });
        if (!ensureCategoriesSelected(requestedCategoryIds)) { return; }
        if ($checkPanel.length && $checkPanel.hasClass('is-active')) {
            closeCheckPanel();
        }

        const starMode = normalizeStarMode(state.star_mode);
        const selectedCats = categories.filter(function (c) {
            return requestedCategoryIds.indexOf(parseInt(c.id, 10) || 0) !== -1;
        });
        if (!selectedCats.length) {
            alert(i18n.noCategories || 'Pick at least one category.');
            return;
        }
        const genderAllowed = isGenderSupportedForSelection(requestedCategoryIds);
        let quizMode = mode || 'practice';
        if (quizMode === 'gender' && !genderAllowed) {
            quizMode = 'practice';
        }
        const sessionWordIds = uniqueIntList(options.session_word_ids || options.sessionWordIds || []);
        const sessionStarModeOverride = (sessionWordIds.length > 0 && starMode === 'only') ? 'normal' : null;
        const effectiveStarMode = sessionStarModeOverride || starMode;

        ensureWordsForCategories(requestedCategoryIds).always(function () {
            const allowedLookup = {};
            sessionWordIds.forEach(function (id) { allowedLookup[id] = true; });
            const filteredCountForCategory = function (cat) {
                const cid = parseInt(cat && cat.id, 10) || 0;
                if (!cid) { return 0; }
                let rows = Array.isArray(wordsByCategory[cid]) ? wordsByCategory[cid] : [];
                if (sessionWordIds.length) {
                    rows = rows.filter(function (word) {
                        const wordId = parseInt(word && word.id, 10) || 0;
                        return !!allowedLookup[wordId];
                    });
                }
                return rows.length;
            };
            const orderedCats = selectedCats.slice();
            if (sessionWordIds.length) {
                orderedCats.sort(function (a, b) {
                    return filteredCountForCategory(b) - filteredCountForCategory(a);
                });
            }
            const catNames = orderedCats.map(function (cat) { return cat.name; });

            const flashData = window.llToolsFlashcardsData || {};
            flashData.categories = orderedCats;
            flashData.categoriesPreselected = true;
            flashData.firstCategoryName = catNames[0] || '';
            const firstCat = orderedCats.length ? orderedCats[0] : null;
            let initialWordsRaw = (flashData.firstCategoryName && firstCat && wordsByCategory[firstCat.id])
                ? wordsByCategory[firstCat.id]
                : [];

            if (sessionWordIds.length) {
                initialWordsRaw = initialWordsRaw.filter(function (word) {
                    const wordId = parseInt(word && word.id, 10) || 0;
                    return !!allowedLookup[wordId];
                });
            }

            if (effectiveStarMode === 'only') {
                const starredLookup = {};
                state.starred_word_ids.forEach(function (id) { starredLookup[id] = true; });
                flashData.firstCategoryData = initialWordsRaw.filter(function (w) { return starredLookup[w.id]; });
            } else {
                flashData.firstCategoryData = initialWordsRaw;
            }

            flashData.wordset = findWordsetSlug(state.wordset_id);
            flashData.wordsetIds = state.wordset_id ? [state.wordset_id] : [];
            flashData.wordsetFallback = false;
            flashData.quiz_mode = quizMode;
            flashData.starMode = starMode;
            flashData.star_mode = starMode;
            flashData.sessionStarModeOverride = sessionStarModeOverride;
            flashData.session_star_mode_override = sessionStarModeOverride;
            flashData.fastTransitions = !!state.fast_transitions;
            flashData.fast_transitions = !!state.fast_transitions;
            flashData.sessionWordIds = sessionWordIds.slice();
            flashData.session_word_ids = sessionWordIds.slice();
            flashData.userStudyState = flashData.userStudyState || {};
            flashData.userStudyState.wordset_id = parseInt(state.wordset_id, 10) || 0;
            flashData.userStudyState.category_ids = requestedCategoryIds.slice();
            applyGenderConfigToFlashcardsData(flashData);
            if (quizMode === 'gender') {
                flashData.genderLaunchSource = 'dashboard';
                if (sessionWordIds.length) {
                    flashData.genderSessionPlan = {
                        word_ids: sessionWordIds.slice(),
                        launch_source: 'dashboard',
                        reason_code: 'dashboard_chunk'
                    };
                    flashData.genderSessionPlanArmed = true;
                    flashData.gender_session_plan_armed = true;
                } else {
                    delete flashData.genderSessionPlan;
                    delete flashData.genderSessionPlanArmed;
                    delete flashData.gender_session_plan_armed;
                }
            } else {
                delete flashData.genderSessionPlan;
                delete flashData.genderSessionPlanArmed;
                delete flashData.gender_session_plan_armed;
                delete flashData.genderLaunchSource;
            }
            window.llToolsFlashcardsData = flashData;

            setStudyPrefsGlobal();
            syncProgressTrackerContext(quizMode, requestedCategoryIds);

            $('body').addClass('ll-tools-flashcard-open');
            const $popup = $('#ll-tools-flashcard-popup');
            const $quizPopup = $('#ll-tools-flashcard-quiz-popup');
            $popup.show();
            $quizPopup.show();

            if (typeof window.initFlashcardWidget === 'function') {
                const initResult = window.initFlashcardWidget(catNames, quizMode);
                if (initResult && typeof initResult.catch === 'function') {
                    initResult.catch(function () {
                        $('body').removeClass('ll-tools-flashcard-open ll-qpg-popup-active').css('overflow', '');
                        $('html').css('overflow', '');
                        $quizPopup.hide();
                        $popup.hide();
                        restoreDashboardScrollIfNeeded();
                    });
                }
            }
        });
    }

    $wordsetSelect.on('change', function () {
        const newId = parseInt($(this).val(), 10) || 0;
        state.wordset_id = newId;
        state.category_ids = [];
        state.starred_word_ids = [];
        state.star_mode = normalizeStarMode(state.star_mode);
        if (newId > 0 && goals.preferred_wordset_ids.indexOf(newId) === -1) {
            goals.preferred_wordset_ids.push(newId);
            goals.preferred_wordset_ids = uniqueIntList(goals.preferred_wordset_ids);
            saveGoalsDebounced();
        }
        setStudyPrefsGlobal();
        reloadForWordset(newId);
        saveStateDebounced();
        renderStarModeToggle();
        renderNextActivity();
        syncProgressTrackerContext();
    });

    $categoriesWrap.on('change', 'input[type="checkbox"]', function () {
        const ids = [];
        $categoriesWrap.find('input[type="checkbox"]:checked').each(function () {
            const id = parseInt($(this).val(), 10) || 0;
            if (!id || isCategoryIgnored(id)) { return; }
            ids.push(id);
        });
        state.category_ids = uniqueIntList(ids);
        updateGenderButtonVisibility();
        saveStateDebounced();
        refreshWordsFromServer();
        syncProgressTrackerContext();
    });

    $root.find('[data-ll-check-all]').on('click', function () {
        state.category_ids = uniqueIntList(categories.map(function (c) { return parseInt(c.id, 10) || 0; })).filter(function (id) {
            return !isCategoryIgnored(id);
        });
        renderCategories();
        saveStateDebounced();
        refreshWordsFromServer();
        syncProgressTrackerContext();
    });

    $root.find('[data-ll-uncheck-all]').on('click', function () {
        state.category_ids = [];
        renderCategories();
        renderWords();
        saveStateDebounced();
        refreshRecommendation();
        syncProgressTrackerContext();
    });

    $categoriesWrap.on('click', '[data-cat-action]', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const catId = parseInt($btn.attr('data-cat-id'), 10) || 0;
        const action = String($btn.attr('data-cat-action') || '');
        if (!catId || !action) { return; }

        if (action === 'skip') {
            const ignored = isCategoryIgnored(catId);
            if (ignored) {
                goals.ignored_category_ids = goals.ignored_category_ids.filter(function (id) { return id !== catId; });
            } else {
                goals.ignored_category_ids.push(catId);
            }
            goals.ignored_category_ids = uniqueIntList(goals.ignored_category_ids);
            ensureSelectedCategoriesRespectGoals();
            renderCategories();
            renderWords();
            saveGoalsDebounced();
            saveStateDebounced();
            refreshWordsFromServer();
            syncProgressTrackerContext();
            return;
        }

        if (action === 'known') {
            const known = isCategoryKnown(catId);
            if (known) {
                goals.placement_known_category_ids = goals.placement_known_category_ids.filter(function (id) { return id !== catId; });
            } else {
                goals.placement_known_category_ids.push(catId);
            }
            goals.placement_known_category_ids = uniqueIntList(goals.placement_known_category_ids);
            renderCategories();
            saveGoalsDebounced();
            refreshRecommendation();
        }
    });

    if ($goalsModes.length) {
        $goalsModes.on('click', '[data-goal-mode]', function (e) {
            e.preventDefault();
            const mode = normalizeProgressMode($(this).attr('data-goal-mode'));
            if (!mode) { return; }
            const enabled = goals.enabled_modes.slice();
            const idx = enabled.indexOf(mode);
            if (idx !== -1) {
                if (enabled.length <= 1) { return; }
                enabled.splice(idx, 1);
            } else {
                enabled.push(mode);
            }
            const order = ['learning', 'practice', 'listening', 'gender', 'self-check'];
            goals.enabled_modes = enabled.filter(function (m, i, arr) {
                return m && arr.indexOf(m) === i;
            }).sort(function (a, b) {
                return order.indexOf(a) - order.indexOf(b);
            });
            renderGoalsControls();
            updateGenderButtonVisibility();
            saveGoalsDebounced();
            refreshRecommendation();
        });
    }

    if ($goalDailyNew.length) {
        $goalDailyNew.on('change blur', function () {
            const value = Math.max(0, Math.min(12, parseInt($(this).val(), 10) || 0));
            goals.daily_new_word_target = value;
            $(this).val(String(value));
            saveGoalsDebounced();
            refreshRecommendation();
        });
    }

    if ($goalMarkKnown.length) {
        $goalMarkKnown.on('click', function () {
            const selected = uniqueIntList(state.category_ids || []);
            if (!selected.length) { return; }
            goals.placement_known_category_ids = uniqueIntList((goals.placement_known_category_ids || []).concat(selected));
            renderCategories();
            saveGoalsDebounced();
            refreshRecommendation();
        });
    }

    if ($goalClearKnown.length) {
        $goalClearKnown.on('click', function () {
            const selectedLookup = {};
            uniqueIntList(state.category_ids || []).forEach(function (id) { selectedLookup[id] = true; });
            if (!Object.keys(selectedLookup).length) { return; }
            goals.placement_known_category_ids = uniqueIntList((goals.placement_known_category_ids || []).filter(function (id) {
                return !selectedLookup[id];
            }));
            renderCategories();
            saveGoalsDebounced();
            refreshRecommendation();
        });
    }

    $wordsWrap.on('click', '.ll-word-star', function () {
        const $btn = $(this);
        const wordId = parseInt($btn.closest('.ll-word-row').data('word-id'), 10);
        if (!wordId) { return; }
        const idx = state.starred_word_ids.indexOf(wordId);
        if (idx === -1) {
            state.starred_word_ids.push(wordId);
        } else {
            state.starred_word_ids.splice(idx, 1);
        }
        setStudyPrefsGlobal();
        saveStateDebounced();
        renderWords();
        renderCategories();
    });

    $root.find('[data-ll-study-start]').on('click', function () {
        const mode = $(this).data('mode') || 'practice';
        startFlashcards(mode, {
            categoryIds: state.category_ids,
            sessionWordIds: []
        });
    });

    if ($startNext.length) {
        $startNext.on('click', function () {
            const next = normalizeNextActivity(nextActivity);
            if (!next || !next.mode) {
                refreshRecommendation();
                return;
            }
            if (!isModeEnabled(next.mode)) {
                refreshRecommendation();
                return;
            }
            const categoryIds = uniqueIntList(next.category_ids.length ? next.category_ids : state.category_ids).filter(function (id) {
                return !isCategoryIgnored(id);
            });
            if (next.mode === 'self-check') {
                syncProgressTrackerContext('self-check', categoryIds);
                startCheckFlow(categoryIds, { mode: 'self-check' });
                return;
            }
            startFlashcards(next.mode, {
                categoryIds: categoryIds,
                sessionWordIds: next.session_word_ids || []
            });
        });
    }

    if ($checkStart.length) {
        $checkStart.on('click', function () {
            startCheckFlow(undefined, { mode: 'self-check' });
        });
    }

    if ($placementStart.length) {
        $placementStart.on('click', function () {
            startPlacementFlow();
        });
    }

    if ($checkFlip.length) {
        $checkFlip.on('click', function () {
            if (!$checkCard.length) { return; }
            const flipped = $checkCard.hasClass('is-flipped');
            setCheckFlipped(!flipped);
        });
    }

    if ($checkKnow.length) {
        $checkKnow.on('click', function () {
            recordCheckResult(true);
        });
    }

    if ($checkUnknown.length) {
        $checkUnknown.on('click', function () {
            recordCheckResult(false);
        });
    }

    if ($checkApply.length) {
        $checkApply.on('click', function () {
            if (isPlacementSession()) {
                applyPlacementResults();
                return;
            }
            applyCheckStars();
        });
    }

    if ($checkRestart.length) {
        $checkRestart.on('click', function () {
            const ids = checkSession && Array.isArray(checkSession.categoryIds)
                ? checkSession.categoryIds
                : state.category_ids;
            const mode = checkSession && checkSession.mode ? checkSession.mode : 'self-check';
            startCheckFlow(ids, { mode: mode });
        });
    }

    if ($checkExit.length) {
        $checkExit.on('click', function () {
            closeCheckPanel();
        });
    }

    // Star mode toggle
    $starModeToggle.on('click', '.ll-study-btn', function () {
        const mode = $(this).data('mode') || 'normal';
        state.star_mode = normalizeStarMode(mode);
        $(this).addClass('active').siblings().removeClass('active');
        setStudyPrefsGlobal();
        saveStateDebounced();
    });

    $transitionToggle.on('click', '.ll-study-btn', function () {
        const speed = $(this).data('speed') || 'slow';
        state.fast_transitions = speed === 'fast';
        $(this).addClass('active').siblings().removeClass('active');
        setStudyPrefsGlobal();
        saveStateDebounced();
    });

    $wordsWrap.on('click', '.ll-group-star', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const $btn = $(this);
        const catId = parseInt($btn.data('cat-id'), 10);
        if (!catId) { return; }

        $btn.prop('disabled', true).addClass('loading');

        ensureWordsForCategory(catId).then(function (words) {
            const ids = (words || []).map(function (w) { return parseInt(w.id, 10) || 0; }).filter(Boolean);
            if (!ids.length) { return; }

            const allStarred = ids.every(function (id) { return isWordStarred(id); });
            if (allStarred) {
                const removeLookup = {};
                ids.forEach(function (id) { removeLookup[id] = true; });
                setStarredWordIds(state.starred_word_ids.filter(function (id) { return !removeLookup[id]; }));
            } else {
                const merged = state.starred_word_ids.slice();
                ids.forEach(function (id) {
                    if (merged.indexOf(id) === -1) { merged.push(id); }
                });
                setStarredWordIds(merged);
            }

            setStudyPrefsGlobal();
            saveStateDebounced();
            renderWords();
        }).always(function () {
            $btn.prop('disabled', false).removeClass('loading');
        });
    });

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

    function handleRecordingButtonClick($btn, buttonEl) {
        const url = $btn.attr('data-audio-url') || '';
        if (!url) { return; }
        const useVisualizer = canUseVisualizerForUrl(url);

        if (currentAudio && currentAudioButton === buttonEl) {
            if (!currentAudio.paused) {
                currentAudio.pause();
                $btn.removeClass('is-playing');
                stopVisualizer();
                return;
            }
            if (useVisualizer) {
                startVisualizer(currentAudio, buttonEl);
            } else {
                stopVisualizer();
            }
            currentAudio.play().catch(function () {
                stopVisualizer();
            });
            return;
        }

        stopCurrentAudio();
        currentAudio = new Audio(url);
        currentAudioButton = buttonEl;
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
        if (useVisualizer) {
            startVisualizer(currentAudio, buttonEl);
        } else {
            stopVisualizer();
        }
        if (currentAudio.play) {
            currentAudio.play().catch(function () {
                stopVisualizer();
            });
        }
    }

    $checkPanel.on('click', '.ll-study-recording-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        handleRecordingButtonClick($(this), this);
    });

    $wordsWrap.on('click', '.ll-study-recording-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        handleRecordingButtonClick($(this), this);
    });

    // Keep dashboard state in sync with in-quiz star changes
    $(document).on('lltools:star-changed', function (_evt, detail) {
        const info = detail || {};
        const wordId = parseInt(info.wordId || info.word_id, 10) || 0;
        if (!wordId) { return; }
        const shouldStar = info.starred !== false;
        const lookup = {};
        (state.starred_word_ids || []).forEach(function (id) { lookup[id] = true; });
        if (shouldStar) { lookup[wordId] = true; }
        else { delete lookup[wordId]; }
        setStarredWordIds(Object.keys(lookup).map(function (k) { return parseInt(k, 10); }));
        setStudyPrefsGlobal();
        saveStateDebounced();
        renderWords();
        renderCategories();
    });

    $(document).on('lltools:progress-updated', function (_evt, detail) {
        const info = detail || {};
        if (Object.prototype.hasOwnProperty.call(info, 'next_activity')) {
            applyNextActivity(info.next_activity);
        } else {
            refreshRecommendation();
        }
    });

    $(document).on('lltools:flashcard-opened', function () {
        if ($checkPanel.length && $checkPanel.hasClass('is-active')) {
            closeCheckPanel();
        }
        stopCurrentAudio();
    });

    $(document).on('lltools:flashcard-closed', function () {
        stopCurrentAudio();
        restoreDashboardScrollIfNeeded();
    });

    // Initial render
    ensureSelectedCategoriesRespectGoals();
    renderWordsets();
    renderGoalsControls();
    renderCategories();
    renderWords();
    renderStarModeToggle();
    renderTransitionToggle();
    updateCheckActionLabels('self-check');
    renderNextActivity();
    updateGenderButtonVisibility();
    setStudyPrefsGlobal();
    syncProgressTrackerContext();

    window.LLToolsStudyDashboard = window.LLToolsStudyDashboard || {};
    window.LLToolsStudyDashboard.startSelfCheck = function (categoryIds) {
        startCheckFlow(Array.isArray(categoryIds) ? categoryIds : undefined, { mode: 'self-check' });
    };
    window.LLToolsStudyDashboard.startPlacementTest = function (categoryIds) {
        startPlacementFlow(Array.isArray(categoryIds) ? categoryIds : undefined);
    };
})(jQuery);
