(function ($) {
    'use strict';

    const cfg = window.llToolsStudyData || {};
    const payload = cfg.payload || {};
    const ajaxUrl = cfg.ajaxUrl || '';
    const nonce = cfg.nonce || '';
    const i18n = cfg.i18n || {};
    const dashboardModeUi = (cfg.modeUi && typeof cfg.modeUi === 'object') ? cfg.modeUi : {};
    const flashcardModeUi = (window.llToolsFlashcardsData && window.llToolsFlashcardsData.modeUi && typeof window.llToolsFlashcardsData.modeUi === 'object')
        ? window.llToolsFlashcardsData.modeUi
        : {};
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
    let wordsetReloadToken = 0;
    let wordsRequestEpoch = 0;
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
    const $checkPanel = $root.find('[data-ll-study-check]');
    const $checkPrompt = $root.find('[data-ll-study-check-prompt]');
    const $checkCategory = $root.find('[data-ll-study-check-category]');
    const $checkProgress = $root.find('[data-ll-study-check-progress]');
    const $checkCard = $root.find('[data-ll-study-check-card]');
    const $checkAnswer = $root.find('[data-ll-study-check-answer]');
    const $checkActions = $root.find('[data-ll-study-check-actions]');
    const $checkComplete = $root.find('[data-ll-study-check-complete]');
    const $checkSummary = $root.find('[data-ll-study-check-summary]');
    const $checkRestart = $root.find('[data-ll-study-check-restart]');
    const $checkExit = $root.find('[data-ll-study-check-exit]');
    const $checkFollowup = $root.find('[data-ll-study-check-followup]');
    const $checkFollowupText = $root.find('[data-ll-study-check-followup-text]');
    const $checkFollowupDifferent = $root.find('[data-ll-study-check-followup-different]');
    const $checkFollowupNext = $root.find('[data-ll-study-check-followup-next]');
    const $nextText = $root.find('[data-ll-study-next-text]');
    const $startNext = $root.find('[data-ll-study-start-next]');
    const $goalsModes = $root.find('[data-ll-goals-modes]');
    const $goalGenderMode = $goalsModes.find('[data-ll-study-goal-gender]');
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
    const CHECK_SPRINKLE_EVERY = 4;
    const CHECK_AUTO_ADVANCE_DELAY_MS = 700;
    const CHECK_AUDIO_FAIL_DELAY_MS = 900;
    const CHECK_AUDIO_TIMEOUT_MS = 10000;
    const SESSION_CHUNK_MIN = 8;
    const SESSION_CHUNK_MAX = 15;
    const SESSION_CHUNK_DEFAULT = 12;
    const CHECK_ACTIONS_CONFIDENCE = [
        { value: 'idk', labelKey: 'checkDontKnow', fallback: "I don't know it", className: 'll-study-check-btn--idk' },
        { value: 'think', labelKey: 'checkThinkKnow', fallback: 'I think I know it', className: 'll-study-check-btn--think' },
        { value: 'know', labelKey: 'checkKnow', fallback: 'I know it', className: 'll-study-check-btn--know' }
    ];
    const CHECK_ACTIONS_RESULT = [
        { value: 'wrong', labelKey: 'checkGotWrong', fallback: 'I got it wrong', className: 'll-study-check-btn--wrong' },
        { value: 'close', labelKey: 'checkGotClose', fallback: 'I got close', className: 'll-study-check-btn--close' },
        { value: 'right', labelKey: 'checkGotRight', fallback: 'I got it right', className: 'll-study-check-btn--right' }
    ];
    let activeChunkLaunch = null;
    let resultsSameChunkPlan = null;
    let resultsDifferentChunkPlan = null;
    let resultsNextChunkPlan = null;
    let checkFollowupDifferentPlan = null;
    let checkFollowupNextPlan = null;
    const lastChunkByMode = {};

    const $resultsActions = $('#ll-study-results-actions');
    const $resultsSuggestion = $('#ll-study-results-suggestion');
    const $resultsSameChunk = $('#ll-study-results-same-chunk');
    const $resultsDifferentChunk = $('#ll-study-results-different-chunk');
    const $resultsNextChunk = $('#ll-study-results-next-chunk');

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

    function normalizeQuizMode(mode) {
        const val = normalizeProgressMode(mode);
        return (val === 'learning' || val === 'practice' || val === 'listening' || val === 'gender') ? val : '';
    }

    function parseBooleanFlag(raw) {
        if (typeof raw === 'boolean') { return raw; }
        if (typeof raw === 'number') { return raw > 0; }
        if (typeof raw === 'string') {
            const normalized = raw.trim().toLowerCase();
            if (normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'on') { return true; }
            if (normalized === '0' || normalized === 'false' || normalized === 'no' || normalized === 'off' || normalized === '') { return false; }
        }
        return !!raw;
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
        const visualConfig = (cfg.visual_config && typeof cfg.visual_config === 'object') ? cfg.visual_config : {};
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
            min_count: parseInt(cfg.min_count, 10) || 0,
            visual_config: visualConfig
        };
    }

    function setGenderConfig(raw) {
        genderConfig = normalizeGenderConfig(raw);
    }

    function toIntList(arr) {
        return (arr || []).map(function (v) { return parseInt(v, 10) || 0; }).filter(function (v) { return v > 0; });
    }

    function getSelectedCategoryIdsFromUI() {
        const ids = [];
        if ($categoriesWrap && $categoriesWrap.length) {
            $categoriesWrap.find('input[type="checkbox"]:checked').each(function () {
                const id = parseInt($(this).val(), 10) || 0;
                if (!id || isCategoryIgnored(id)) { return; }
                ids.push(id);
            });
        }
        return uniqueIntList(ids);
    }

    function findWordsetSlug(id) {
        const ws = wordsets.find(function (w) { return parseInt(w.id, 10) === parseInt(id, 10); });
        return ws ? ws.slug : '';
    }

    function getCurrentWordsetId() {
        return parseInt(state.wordset_id, 10) || 0;
    }

    function bumpWordsRequestEpoch() {
        wordsRequestEpoch += 1;
        return wordsRequestEpoch;
    }

    function wordMatchesWordset(word, wordsetId) {
        const wsId = parseInt(wordsetId, 10) || 0;
        if (!wsId) { return true; }
        const ids = Array.isArray(word && word.wordset_ids) ? word.wordset_ids : [];
        return ids.some(function (id) {
            return (parseInt(id, 10) || 0) === wsId;
        });
    }

    function categoryWordsMatchWordset(words, wordsetId) {
        const list = Array.isArray(words) ? words : [];
        if (!list.length) { return false; }
        return list.every(function (word) {
            return wordMatchesWordset(word, wordsetId);
        });
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

    function modeIconFallback(mode) {
        const key = normalizeProgressMode(mode);
        if (key === 'learning') { return '🎓'; }
        if (key === 'practice') { return '❓'; }
        if (key === 'listening') { return '🎧'; }
        if (key === 'gender') { return '⚥'; }
        if (key === 'self-check') { return '✔✖'; }
        return '';
    }

    function getModeUiConfig(mode) {
        const key = normalizeProgressMode(mode);
        if (!key) { return {}; }
        const fromDashboard = (dashboardModeUi[key] && typeof dashboardModeUi[key] === 'object')
            ? dashboardModeUi[key]
            : null;
        if (fromDashboard) { return fromDashboard; }
        const fromFlashcards = (flashcardModeUi[key] && typeof flashcardModeUi[key] === 'object')
            ? flashcardModeUi[key]
            : null;
        return fromFlashcards || {};
    }

    function getPlanCategorySummary(plan) {
        const ids = uniqueIntList((plan && plan.category_ids) || []);
        const labels = ids.map(getCategoryLabelById).filter(Boolean);
        if (!labels.length) {
            return i18n.categoriesLabel || 'Categories';
        }
        if (labels.length === 1) {
            return labels[0];
        }
        if (labels.length === 2) {
            return labels[0] + ', ' + labels[1];
        }
        return labels[0] + ' +' + String(labels.length - 1);
    }

    function buildModeIconElement(mode, fallbackEmoji) {
        const ui = getModeUiConfig(mode);
        const svg = String(ui && ui.svg ? ui.svg : '').trim();
        if (svg) {
            const $icon = $('<span>', { class: 'll-vocab-lesson-mode-icon', 'aria-hidden': 'true' });
            $icon.html(svg);
            return $icon;
        }
        const emoji = String(ui && ui.icon ? ui.icon : (fallbackEmoji || modeIconFallback(mode))).trim();
        if (!emoji) {
            return null;
        }
        return $('<span>', {
            class: 'll-vocab-lesson-mode-icon',
            'aria-hidden': 'true',
            'data-emoji': emoji
        });
    }

    function styleFollowupButton($button) {
        if (!$button || !$button.length) { return; }
        $button
            .removeClass('quiz-mode-button')
            .removeClass('ghost')
            .addClass('ll-study-btn ll-vocab-lesson-mode-button ll-study-followup-mode-button');
    }

    function setModeActionButtonContent($button, mode, labelText, fallbackEmoji) {
        if (!$button || !$button.length) { return; }
        const label = String(labelText || '').trim();
        styleFollowupButton($button);
        $button.empty();
        const $icon = buildModeIconElement(mode, fallbackEmoji);
        if ($icon) {
            $button.append($icon);
        }
        $button.append($('<span>', {
            class: 'll-vocab-lesson-mode-label',
            text: label
        }));
    }

    function setRedoActionButtonContent($button, labelText) {
        setModeActionButtonContent($button, '', labelText, '↻');
    }

    function setPlanActionButtonContent($button, plan, fallbackText, fallbackMode) {
        const target = (plan && typeof plan === 'object') ? plan : null;
        const label = target ? getPlanCategorySummary(target) : String(fallbackText || '');
        const mode = normalizeProgressMode((target && target.mode) || fallbackMode || '');
        if (!mode) {
            setModeActionButtonContent($button, '', label, '');
            return;
        }
        setModeActionButtonContent($button, mode, label, modeIconFallback(mode));
    }

    function setCategorySwitchActionButtonContent($button, currentMode, plan, fallbackText) {
        const target = (plan && typeof plan === 'object') ? plan : null;
        const label = target ? getPlanCategorySummary(target) : String(fallbackText || '');
        const mode = normalizeProgressMode(currentMode || (target && target.mode) || '');
        setModeActionButtonContent($button, mode, label, modeIconFallback(mode));
    }

    function getFollowupCategoryIds(preferredIds) {
        const preferred = uniqueIntList(preferredIds || []).filter(function (id) {
            return !isCategoryIgnored(id);
        });
        const selected = uniqueIntList(state.category_ids || []).filter(function (id) {
            return !isCategoryIgnored(id);
        });
        const merged = uniqueIntList(selected.concat(preferred));
        if (merged.length) {
            return merged;
        }
        return preferred;
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

    function hideResultsChunkActions() {
        resultsSameChunkPlan = null;
        resultsDifferentChunkPlan = null;
        resultsNextChunkPlan = null;
        if ($resultsSuggestion.length) {
            $resultsSuggestion.text('').hide();
        }
        if ($resultsSameChunk.length) {
            $resultsSameChunk.hide().prop('disabled', false);
        }
        if ($resultsDifferentChunk.length) {
            $resultsDifferentChunk.hide().prop('disabled', false);
        }
        if ($resultsNextChunk.length) {
            $resultsNextChunk.hide().prop('disabled', false);
        }
        if ($resultsActions.length) {
            $resultsActions.hide();
        }
    }

    function setActiveChunkLaunch(mode, categoryIds, sessionWordIds, source) {
        const normalizedMode = normalizeProgressMode(mode) || 'practice';
        activeChunkLaunch = {
            mode: normalizedMode,
            category_ids: uniqueIntList(categoryIds || []).filter(function (id) { return !isCategoryIgnored(id); }),
            session_word_ids: uniqueIntList(sessionWordIds || []),
            source: String(source || 'dashboard')
        };
        lastChunkByMode[normalizedMode] = {
            mode: normalizedMode,
            category_ids: activeChunkLaunch.category_ids.slice(),
            session_word_ids: activeChunkLaunch.session_word_ids.slice(),
            source: activeChunkLaunch.source
        };
    }

    function getCategoryExposureScore(catId) {
        const cid = parseInt(catId, 10) || 0;
        if (!cid) { return Number.MAX_SAFE_INTEGER; }
        const progress = categoryProgress[cid];
        if (!progress || typeof progress !== 'object') { return 0; }
        return Math.max(0, parseInt(progress.exposure_total, 10) || 0);
    }

    function getCategoryModeExposureScore(catId, mode) {
        const cid = parseInt(catId, 10) || 0;
        if (!cid) { return 0; }
        const key = normalizeProgressMode(mode);
        if (!key) { return 0; }
        const progress = categoryProgress[cid];
        if (!progress || typeof progress !== 'object') { return 0; }
        const byMode = (progress.exposure_by_mode && typeof progress.exposure_by_mode === 'object')
            ? progress.exposure_by_mode
            : {};
        return Math.max(0, parseInt(byMode[key], 10) || 0);
    }

    function sortNumericAsc(values) {
        return uniqueIntList(values || []).sort(function (left, right) {
            return left - right;
        });
    }

    function areIntListsEqual(left, right) {
        const a = sortNumericAsc(left);
        const b = sortNumericAsc(right);
        if (a.length !== b.length) { return false; }
        for (let i = 0; i < a.length; i++) {
            if (a[i] !== b[i]) { return false; }
        }
        return true;
    }

    function areChunkPlansEquivalent(leftPlan, rightPlan) {
        const left = leftPlan && typeof leftPlan === 'object' ? leftPlan : null;
        const right = rightPlan && typeof rightPlan === 'object' ? rightPlan : null;
        if (!left || !right) { return false; }

        const leftWords = uniqueIntList(left.session_word_ids || []);
        const rightWords = uniqueIntList(right.session_word_ids || []);
        if (leftWords.length || rightWords.length) {
            return areIntListsEqual(leftWords, rightWords);
        }

        return areIntListsEqual(left.category_ids || [], right.category_ids || []);
    }

    function arePlansDuplicate(leftPlan, rightPlan) {
        const left = leftPlan && typeof leftPlan === 'object' ? leftPlan : null;
        const right = rightPlan && typeof rightPlan === 'object' ? rightPlan : null;
        if (!left || !right) { return false; }
        if (normalizeProgressMode(left.mode) !== normalizeProgressMode(right.mode)) {
            return false;
        }
        return areChunkPlansEquivalent(left, right);
    }

    function hasChunkPlanTarget(plan) {
        const target = plan && typeof plan === 'object' ? plan : null;
        if (!target) { return false; }
        return uniqueIntList(target.category_ids || []).length > 0 ||
            uniqueIntList(target.session_word_ids || []).length > 0;
    }

    function buildRecommendedModeSequence(categoryIds) {
        const ids = uniqueIntList(categoryIds || []).filter(function (id) { return !isCategoryIgnored(id); });
        const sequence = [];

        if (isModeEnabled('learning')) {
            sequence.push('learning');
        }
        if (isModeEnabled('gender') && isGenderSupportedForSelection(ids)) {
            sequence.push('gender');
        }
        if (isModeEnabled('practice')) {
            sequence.push('practice');
        }
        if (isModeEnabled('self-check')) {
            sequence.push('self-check');
        }
        if (isModeEnabled('listening')) {
            sequence.push('listening');
        }
        if (!sequence.length) {
            ['learning', 'practice', 'self-check', 'listening'].forEach(function (mode) {
                if (sequence.indexOf(mode) === -1) {
                    sequence.push(mode);
                }
            });
        }

        return sequence.filter(function (mode, idx, arr) {
            return idx === arr.indexOf(mode);
        });
    }

    function pickRecommendedModeExcluding(currentMode, categoryIds) {
        const sequence = buildRecommendedModeSequence(categoryIds);
        if (!sequence.length) {
            return '';
        }
        const current = normalizeProgressMode(currentMode);
        let idx = sequence.indexOf(current);
        if (idx === -1) {
            idx = 0;
        }
        for (let step = 1; step <= sequence.length; step++) {
            const candidate = sequence[(idx + step) % sequence.length];
            if (candidate && candidate !== current) {
                return candidate;
            }
        }
        return '';
    }

    function getModeEligibleCategoryIds(mode, categoryIds) {
        const normalizedMode = normalizeProgressMode(mode) || 'practice';
        const requested = uniqueIntList(categoryIds || []).filter(function (id) {
            return !isCategoryIgnored(id);
        });
        if (!requested.length) {
            return [];
        }
        if (normalizedMode !== 'gender') {
            return requested;
        }
        return requested.filter(function (cid) {
            const cat = getCategoryById(cid);
            return !!(cat && parseBooleanFlag(cat.gender_supported));
        });
    }

    function buildModeCategoryOrder(mode, categoryIds, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const normalizedMode = normalizeProgressMode(mode) || 'practice';
        const eligible = getModeEligibleCategoryIds(normalizedMode, categoryIds);
        if (!eligible.length) {
            return [];
        }

        const avoidCategoryLookup = {};
        uniqueIntList(opts.avoid_category_ids || []).forEach(function (id) {
            avoidCategoryLookup[id] = true;
        });
        const preferDifferent = !!opts.prefer_different;

        const scored = eligible.map(function (cid) {
            const words = getCategoryWords(cid) || [];
            const uniqueWordCount = uniqueIntList(words.map(function (word) {
                return parseInt(word && word.id, 10) || 0;
            })).length;
            return {
                id: cid,
                modeExposure: getCategoryModeExposureScore(cid, normalizedMode),
                totalExposure: getCategoryExposureScore(cid),
                naturalChunk: uniqueWordCount >= SESSION_CHUNK_MIN && uniqueWordCount <= SESSION_CHUNK_MAX ? 1 : 0
            };
        });

        scored.sort(function (left, right) {
            const leftAvoided = avoidCategoryLookup[left.id] ? 1 : 0;
            const rightAvoided = avoidCategoryLookup[right.id] ? 1 : 0;
            if (preferDifferent && leftAvoided !== rightAvoided) {
                return leftAvoided - rightAvoided;
            }
            if (left.modeExposure !== right.modeExposure) {
                return left.modeExposure - right.modeExposure;
            }
            if (left.totalExposure !== right.totalExposure) {
                return left.totalExposure - right.totalExposure;
            }
            if (left.naturalChunk !== right.naturalChunk) {
                return right.naturalChunk - left.naturalChunk;
            }
            return left.id - right.id;
        });

        return scored.map(function (row) { return row.id; });
    }

    function resolveSessionChunkTargetSize(totalWordCount, preferredSize) {
        const pool = Math.max(0, parseInt(totalWordCount, 10) || 0);
        if (!pool) {
            return 0;
        }
        if (pool <= SESSION_CHUNK_MAX) {
            return pool;
        }

        let target = parseInt(preferredSize, 10);
        if (!Number.isFinite(target)) {
            target = SESSION_CHUNK_DEFAULT;
        }
        target = Math.max(SESSION_CHUNK_MIN, Math.min(SESSION_CHUNK_MAX, target));

        if (pool >= 60) {
            target = 15;
        } else if (pool >= 40) {
            target = 13;
        } else if (pool >= 24) {
            target = 12;
        } else {
            target = 10;
        }

        return Math.max(SESSION_CHUNK_MIN, Math.min(SESSION_CHUNK_MAX, Math.min(pool, target)));
    }

    function sortWordsForChunk(rows) {
        const list = shuffleItems(Array.isArray(rows) ? rows.slice() : []);
        list.sort(function (left, right) {
            const leftStage = getWordProgressStage(left);
            const rightStage = getWordProgressStage(right);
            if (leftStage !== rightStage) {
                return leftStage - rightStage;
            }
            const leftCoverage = getWordProgressCoverage(left);
            const rightCoverage = getWordProgressCoverage(right);
            if (leftCoverage !== rightCoverage) {
                return leftCoverage - rightCoverage;
            }
            return 0;
        });
        return list;
    }

    function buildFallbackChunkPlan(categoryIds, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const requested = uniqueIntList(categoryIds || []).filter(function (id) { return !isCategoryIgnored(id); });
        if (!requested.length) {
            return { category_ids: [], session_word_ids: [] };
        }

        const preferredOrder = uniqueIntList(opts.category_order || []).filter(function (id) {
            return requested.indexOf(id) !== -1;
        });
        const remaining = requested.filter(function (id) {
            return preferredOrder.indexOf(id) === -1;
        }).sort(function (left, right) {
            return getCategoryExposureScore(left) - getCategoryExposureScore(right);
        });
        const categoryOrder = preferredOrder.concat(remaining);

        const avoidLookup = {};
        uniqueIntList(opts.avoid_word_ids || []).forEach(function (id) {
            avoidLookup[id] = true;
        });
        const hasAvoidWords = Object.keys(avoidLookup).length > 0;

        const collectCategoryWordIds = function (cid, includeAvoided) {
            const words = sortWordsForChunk(getCategoryWords(cid) || []);
            const seenInCategory = {};
            const out = [];
            for (let j = 0; j < words.length; j++) {
                const wordId = parseInt(words[j] && words[j].id, 10) || 0;
                if (!wordId || seenInCategory[wordId]) { continue; }
                if (!includeAvoided && avoidLookup[wordId]) { continue; }
                seenInCategory[wordId] = true;
                out.push(wordId);
            }
            return out;
        };

        const findNaturalCategoryChunk = function (includeAvoided) {
            for (let i = 0; i < categoryOrder.length; i++) {
                const cid = categoryOrder[i];
                const categoryWordIds = collectCategoryWordIds(cid, includeAvoided);
                if (categoryWordIds.length >= SESSION_CHUNK_MIN && categoryWordIds.length <= SESSION_CHUNK_MAX) {
                    return {
                        category_ids: [cid],
                        session_word_ids: categoryWordIds
                    };
                }
            }
            return null;
        };

        const naturalChunk = findNaturalCategoryChunk(false);
        if (naturalChunk) {
            return naturalChunk;
        }
        if (hasAvoidWords) {
            const naturalChunkWithAvoided = findNaturalCategoryChunk(true);
            if (naturalChunkWithAvoided) {
                return naturalChunkWithAvoided;
            }
        }

        const buildMixedChunk = function (includeAvoided) {
            const poolLookup = {};
            categoryOrder.forEach(function (cid) {
                collectCategoryWordIds(cid, includeAvoided).forEach(function (wordId) {
                    if (wordId > 0) {
                        poolLookup[wordId] = true;
                    }
                });
            });

            const poolSize = Object.keys(poolLookup).length;
            const chunkTargetSize = resolveSessionChunkTargetSize(poolSize);
            const seenWordLookup = {};
            const selectedWordIds = [];
            const usedCategoryLookup = {};

            for (let i = 0; i < categoryOrder.length; i++) {
                const cid = categoryOrder[i];
                const categoryWordIds = collectCategoryWordIds(cid, includeAvoided);
                for (let j = 0; j < categoryWordIds.length; j++) {
                    const wordId = categoryWordIds[j];
                    if (!wordId || seenWordLookup[wordId]) { continue; }
                    seenWordLookup[wordId] = true;
                    selectedWordIds.push(wordId);
                    usedCategoryLookup[cid] = true;
                    if (chunkTargetSize > 0 && selectedWordIds.length >= chunkTargetSize) {
                        break;
                    }
                }
                if (chunkTargetSize > 0 && selectedWordIds.length >= chunkTargetSize) {
                    break;
                }
            }

            const outCategories = uniqueIntList(Object.keys(usedCategoryLookup).map(function (key) {
                return parseInt(key, 10) || 0;
            }));

            return {
                category_ids: outCategories.length ? outCategories : categoryOrder.slice(0, 3),
                session_word_ids: chunkTargetSize > 0 ? selectedWordIds.slice(0, chunkTargetSize) : []
            };
        };

        const mixedChunk = buildMixedChunk(false);
        if (mixedChunk.session_word_ids.length || !hasAvoidWords) {
            return mixedChunk;
        }
        return buildMixedChunk(true);
    }

    function buildModeChunkPlanFromRecommendation(mode, categoryIds, recommendation) {
        const normalizedMode = normalizeProgressMode(mode);
        if (!normalizedMode) {
            return null;
        }
        const next = normalizeNextActivity(recommendation || nextActivity);
        if (!next || normalizeProgressMode(next.mode) !== normalizedMode) {
            return null;
        }
        const allowedCategories = uniqueIntList(categoryIds || []).filter(function (id) {
            return !isCategoryIgnored(id);
        });
        if (!allowedCategories.length) {
            return null;
        }

        let recCategories = uniqueIntList(next.category_ids || []).filter(function (id) {
            return allowedCategories.indexOf(id) !== -1;
        });
        if (!recCategories.length) {
            recCategories = allowedCategories.slice();
        }

        const recWords = uniqueIntList(next.session_word_ids || []);
        if (!recWords.length) {
            return null;
        }

        return {
            mode: normalizedMode,
            category_ids: recCategories,
            session_word_ids: recWords,
            source: 'dashboard_chunk_recommendation'
        };
    }

    function fetchRecommendationForCategories(categoryIds) {
        const deferred = $.Deferred();
        const ids = uniqueIntList(categoryIds || []).filter(function (id) {
            return !isCategoryIgnored(id);
        });
        if (!ids.length) {
            deferred.resolve(null);
            return deferred.promise();
        }
        $.post(ajaxUrl, {
            action: 'll_user_study_recommendation',
            nonce: nonce,
            wordset_id: state.wordset_id,
            category_ids: ids
        }).done(function (res) {
            let next = null;
            if (res && res.success && res.data && Object.prototype.hasOwnProperty.call(res.data, 'next_activity')) {
                next = normalizeNextActivity(res.data.next_activity);
                applyNextActivity(next);
            }
            deferred.resolve(next);
        }).fail(function () {
            deferred.resolve(null);
        });
        return deferred.promise();
    }

    function resolveChunkPlanForMode(mode, categoryIds, options) {
        const deferred = $.Deferred();
        const opts = (options && typeof options === 'object') ? options : {};
        const normalizedMode = normalizeProgressMode(mode) || 'practice';
        const requestedIds = uniqueIntList(categoryIds || []).filter(function (id) {
            return !isCategoryIgnored(id);
        });
        if (!requestedIds.length) {
            deferred.resolve({
                mode: normalizedMode,
                category_ids: [],
                session_word_ids: [],
                source: String(opts.source || 'dashboard_chunk_manual'),
                recommendation: null
            });
            return deferred.promise();
        }

        ensureWordsForCategories(requestedIds).always(function () {
            fetchRecommendationForCategories(requestedIds).done(function (fetchedRecommendation) {
                const modeCategories = getModeEligibleCategoryIds(normalizedMode, requestedIds);
                const baseCategories = modeCategories.length ? modeCategories : requestedIds;
                const priorPlan = (opts.prior_plan && typeof opts.prior_plan === 'object')
                    ? opts.prior_plan
                    : (lastChunkByMode[normalizedMode] || null);
                const preferDifferent = !!opts.prefer_different;
                const avoidWordIds = (preferDifferent && priorPlan)
                    ? uniqueIntList(priorPlan.session_word_ids || [])
                    : [];
                const avoidCategoryIds = (preferDifferent && priorPlan)
                    ? uniqueIntList(priorPlan.category_ids || [])
                    : [];

                const recommendedPlan = buildModeChunkPlanFromRecommendation(normalizedMode, baseCategories, fetchedRecommendation);
                let launchCategoryIds = [];
                let sessionWordIds = [];

                if (recommendedPlan && !(preferDifferent && priorPlan && areChunkPlansEquivalent(recommendedPlan, priorPlan))) {
                    launchCategoryIds = uniqueIntList(recommendedPlan.category_ids || []);
                    sessionWordIds = uniqueIntList(recommendedPlan.session_word_ids || []);
                } else {
                    const categoryOrder = buildModeCategoryOrder(normalizedMode, baseCategories, {
                        prefer_different: preferDifferent,
                        avoid_category_ids: avoidCategoryIds
                    });
                    const fallback = buildFallbackChunkPlan(baseCategories, {
                        category_order: categoryOrder,
                        avoid_word_ids: avoidWordIds
                    });
                    launchCategoryIds = uniqueIntList(fallback.category_ids || []);
                    sessionWordIds = uniqueIntList(fallback.session_word_ids || []);
                    if (!launchCategoryIds.length) {
                        launchCategoryIds = baseCategories.slice();
                    }
                }

                if (preferDifferent && priorPlan) {
                    const candidatePlan = {
                        category_ids: launchCategoryIds,
                        session_word_ids: sessionWordIds
                    };
                    if (areChunkPlansEquivalent(candidatePlan, priorPlan)) {
                        const retryFallback = buildFallbackChunkPlan(baseCategories, {
                            category_order: buildModeCategoryOrder(normalizedMode, baseCategories, {
                                prefer_different: false,
                                avoid_category_ids: []
                            }),
                            avoid_word_ids: []
                        });
                        const retryPlan = {
                            category_ids: uniqueIntList(retryFallback.category_ids || []),
                            session_word_ids: uniqueIntList(retryFallback.session_word_ids || [])
                        };
                        if (!areChunkPlansEquivalent(retryPlan, priorPlan)) {
                            launchCategoryIds = retryPlan.category_ids.length ? retryPlan.category_ids : launchCategoryIds;
                            sessionWordIds = retryPlan.session_word_ids.length ? retryPlan.session_word_ids : sessionWordIds;
                        } else if (opts.require_different) {
                            launchCategoryIds = [];
                            sessionWordIds = [];
                        }
                    }
                }

                deferred.resolve({
                    mode: normalizedMode,
                    category_ids: launchCategoryIds,
                    session_word_ids: sessionWordIds,
                    source: String(opts.source || 'dashboard_chunk_manual'),
                    recommendation: null
                });
            });
        });

        return deferred.promise();
    }

    function buildNextChunkPlanFromActivity(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const disallowMode = normalizeProgressMode(opts.disallow_mode || opts.disallowMode);
        const next = normalizeNextActivity(nextActivity);
        if (!next || !next.mode || !isModeEnabled(next.mode)) {
            return null;
        }
        if (disallowMode && normalizeProgressMode(next.mode) === disallowMode) {
            return null;
        }
        let categoryIds = uniqueIntList(next.category_ids || []).filter(function (id) { return !isCategoryIgnored(id); });
        if (!categoryIds.length) {
            categoryIds = uniqueIntList(state.category_ids || []).filter(function (id) { return !isCategoryIgnored(id); });
        }
        if (!categoryIds.length) {
            return null;
        }
        const modeCategoryIds = getModeEligibleCategoryIds(next.mode, categoryIds);
        if (!modeCategoryIds.length) {
            return null;
        }

        let sessionWordIds = uniqueIntList(next.session_word_ids || []);
        if (!sessionWordIds.length) {
            const fallback = buildFallbackChunkPlan(modeCategoryIds, {
                category_order: buildModeCategoryOrder(next.mode, modeCategoryIds, { prefer_different: false })
            });
            sessionWordIds = uniqueIntList(fallback.session_word_ids || []);
            if (fallback.category_ids && fallback.category_ids.length) {
                categoryIds = uniqueIntList(fallback.category_ids).filter(function (id) { return !isCategoryIgnored(id); });
            } else {
                categoryIds = modeCategoryIds.slice();
            }
        } else {
            categoryIds = modeCategoryIds.slice();
        }

        return {
            mode: next.mode,
            category_ids: categoryIds,
            session_word_ids: sessionWordIds,
            source: 'dashboard_chunk_next'
        };
    }

    function resolveRecommendedFollowupPlan(currentMode, categoryIds) {
        const deferred = $.Deferred();
        const disallow = normalizeProgressMode(currentMode);
        const directPlan = buildNextChunkPlanFromActivity({ disallow_mode: disallow });
        if (directPlan) {
            deferred.resolve(directPlan);
            return deferred.promise();
        }

        const ids = uniqueIntList(categoryIds || []).filter(function (id) { return !isCategoryIgnored(id); });
        const fallbackMode = pickRecommendedModeExcluding(disallow, ids);
        if (!fallbackMode || fallbackMode === disallow) {
            deferred.resolve(null);
            return deferred.promise();
        }

        resolveChunkPlanForMode(fallbackMode, ids, {
            prefer_different: true,
            source: 'dashboard_chunk_next'
        }).done(function (plan) {
            if (plan && plan.mode && uniqueIntList(plan.category_ids || []).length) {
                deferred.resolve(plan);
            } else {
                deferred.resolve(null);
            }
        }).fail(function () {
            deferred.resolve(null);
        });

        return deferred.promise();
    }

    function updateResultsChunkActionCopy() {
        if (!$resultsActions.length) { return; }

        const samePlan = resultsSameChunkPlan;
        const differentPlan = resultsDifferentChunkPlan;
        const nextPlan = resultsNextChunkPlan;
        const currentMode = normalizeProgressMode((samePlan && samePlan.mode) || (activeChunkLaunch && activeChunkLaunch.mode) || '');
        const nextMode = normalizeProgressMode(nextPlan && nextPlan.mode);
        const showSame = !!samePlan;
        const showNext = !!nextPlan &&
            !!nextMode &&
            !!currentMode &&
            nextMode !== currentMode &&
            !(showSame && arePlansDuplicate(nextPlan, samePlan));
        const showDifferent = !!differentPlan &&
            !(showSame && arePlansDuplicate(differentPlan, samePlan)) &&
            !(showNext && arePlansDuplicate(differentPlan, nextPlan));

        if (showSame && $resultsSameChunk.length) {
            setRedoActionButtonContent($resultsSameChunk, i18n.resultsRedoChunk || 'Repeat');
            $resultsSameChunk.show().prop('disabled', false);
        } else if ($resultsSameChunk.length) {
            $resultsSameChunk.hide().prop('disabled', false);
        }

        if ($resultsDifferentChunk.length) {
            if (showDifferent) {
                setCategorySwitchActionButtonContent($resultsDifferentChunk, currentMode, differentPlan, i18n.categoriesLabel || 'Categories');
                $resultsDifferentChunk.show().prop('disabled', false);
            } else {
                $resultsDifferentChunk.hide().prop('disabled', false);
            }
        }

        if ($resultsNextChunk.length) {
            if (showNext) {
                setPlanActionButtonContent($resultsNextChunk, nextPlan, i18n.categoriesLabel || 'Categories', currentMode);
                $resultsNextChunk.show().prop('disabled', false);
            } else {
                $resultsNextChunk.hide().prop('disabled', false);
            }
        }

        if ($resultsSuggestion.length) {
            $resultsSuggestion.text('').hide();
        }

        if (showSame || showDifferent || showNext) {
            $('#quiz-mode-buttons').hide();
            $('#ll-gender-results-actions').hide();
            $('#restart-quiz').hide();
            $resultsActions.show();
        } else {
            $resultsActions.hide();
        }
    }

    function renderResultsChunkActions(resultMode) {
        hideResultsChunkActions();
        if (!$resultsActions.length) {
            return;
        }

        const launch = activeChunkLaunch;
        if (!launch) {
            return;
        }

        const currentMode = normalizeProgressMode(resultMode || launch.mode);
        if (!currentMode) {
            return;
        }

        const categoryIds = getFollowupCategoryIds(launch.category_ids || []);
        if (!categoryIds.length) {
            return;
        }

        const initializeResultPlans = function (samePlan) {
            if (!hasChunkPlanTarget(samePlan)) {
                return;
            }
            const sameCategoryIds = uniqueIntList(samePlan.category_ids || []);

            resultsSameChunkPlan = {
                mode: currentMode,
                category_ids: sameCategoryIds.length ? sameCategoryIds : categoryIds.slice(),
                session_word_ids: uniqueIntList(samePlan.session_word_ids || []),
                source: 'dashboard_chunk_repeat'
            };
            resultsDifferentChunkPlan = null;
            resultsNextChunkPlan = null;
            updateResultsChunkActionCopy();

            resolveChunkPlanForMode(currentMode, categoryIds, {
                prefer_different: true,
                require_different: false,
                prior_plan: resultsSameChunkPlan,
                source: 'dashboard_chunk_mode_next'
            }).done(function (plan) {
                if (hasChunkPlanTarget(plan)) {
                    resultsDifferentChunkPlan = plan;
                    updateResultsChunkActionCopy();
                    return;
                }
                resolveChunkPlanForMode(currentMode, categoryIds, {
                    prefer_different: false,
                    require_different: false,
                    source: 'dashboard_chunk_mode_next'
                }).done(function (fallbackPlan) {
                    resultsDifferentChunkPlan = hasChunkPlanTarget(fallbackPlan) ? fallbackPlan : null;
                    updateResultsChunkActionCopy();
                });
            });

            resolveRecommendedFollowupPlan(currentMode, categoryIds).done(function (plan) {
                resultsNextChunkPlan = plan;
                updateResultsChunkActionCopy();
            });

            refreshRecommendation().always(function () {
                resolveRecommendedFollowupPlan(currentMode, categoryIds).done(function (plan) {
                    resultsNextChunkPlan = plan;
                    updateResultsChunkActionCopy();
                });
            });
        };

        const initialSessionWordIds = uniqueIntList(launch.session_word_ids || []);
        if (initialSessionWordIds.length) {
            initializeResultPlans({
                category_ids: categoryIds.slice(),
                session_word_ids: initialSessionWordIds
            });
            return;
        }

        resolveChunkPlanForMode(currentMode, categoryIds, {
            prefer_different: false,
            require_different: false,
            source: 'dashboard_chunk_repeat'
        }).done(function (plan) {
            initializeResultPlans(plan);
        });
    }

    function hideCheckFollowupActions() {
        checkFollowupDifferentPlan = null;
        checkFollowupNextPlan = null;
        if ($checkFollowupText.length) {
            $checkFollowupText.text('').hide();
        }
        if ($checkFollowupDifferent.length) {
            $checkFollowupDifferent.prop('disabled', false).hide();
        }
        if ($checkFollowupNext.length) {
            $checkFollowupNext.prop('disabled', false).hide();
        }
        if ($checkFollowup.length) {
            $checkFollowup.hide();
        }
    }

    function getCurrentSelfCheckRepeatPlan() {
        if (!checkSession) {
            return null;
        }
        let chunkWordIds = uniqueIntList(checkSession.sessionWordIds || []);
        if (!chunkWordIds.length && Array.isArray(checkSession.items)) {
            chunkWordIds = uniqueIntList(checkSession.items.map(function (item) {
                return parseInt(item && item.wordId, 10) || 0;
            }));
        }
        const categoryIds = uniqueIntList(checkSession.categoryIds || []).filter(function (id) {
            return !isCategoryIgnored(id);
        });
        if (!chunkWordIds.length || !categoryIds.length) {
            return null;
        }
        return {
            mode: 'self-check',
            category_ids: categoryIds.slice(),
            session_word_ids: chunkWordIds.slice(),
            source: 'dashboard_chunk_repeat'
        };
    }

    function updateCheckFollowupActionCopy() {
        if (!$checkFollowup.length) {
            return;
        }

        const repeatPlan = getCurrentSelfCheckRepeatPlan();
        const differentPlan = checkFollowupDifferentPlan;
        const nextPlan = checkFollowupNextPlan;
        const currentMode = normalizeProgressMode((repeatPlan && repeatPlan.mode) || 'self-check');
        const nextMode = normalizeProgressMode(nextPlan && nextPlan.mode);
        const showNext = !!nextPlan &&
            !!nextMode &&
            nextMode !== 'self-check' &&
            !(repeatPlan && arePlansDuplicate(nextPlan, repeatPlan));
        const showDifferent = !!differentPlan &&
            !(repeatPlan && arePlansDuplicate(differentPlan, repeatPlan)) &&
            !(showNext && arePlansDuplicate(differentPlan, nextPlan));

        if ($checkFollowupDifferent.length) {
            if (showDifferent) {
                setCategorySwitchActionButtonContent($checkFollowupDifferent, currentMode, differentPlan, i18n.categoriesLabel || 'Categories');
                $checkFollowupDifferent.show().prop('disabled', false);
            } else {
                $checkFollowupDifferent.hide().prop('disabled', false);
            }
        }

        if ($checkFollowupNext.length) {
            if (showNext) {
                setPlanActionButtonContent($checkFollowupNext, nextPlan, i18n.categoriesLabel || 'Categories', currentMode);
                $checkFollowupNext.show().prop('disabled', false);
            } else {
                $checkFollowupNext.hide().prop('disabled', false);
            }
        }

        if ($checkFollowupText.length) {
            $checkFollowupText.text('').hide();
        }

        if (showDifferent || showNext) {
            $checkFollowup.css('display', 'inline-flex');
        } else {
            $checkFollowup.hide();
        }
    }

    function renderCheckFollowupActions() {
        hideCheckFollowupActions();
        if (!$checkFollowup.length || !checkSession) {
            return;
        }
        const currentSelfCheckPlan = getCurrentSelfCheckRepeatPlan();
        if (!currentSelfCheckPlan || !hasChunkPlanTarget(currentSelfCheckPlan)) {
            return;
        }
        const categoryIds = uniqueIntList(currentSelfCheckPlan.category_ids || []);

        checkFollowupDifferentPlan = null;
        checkFollowupNextPlan = null;
        updateCheckFollowupActionCopy();

        const followupCategoryIds = getFollowupCategoryIds(categoryIds);
        resolveChunkPlanForMode('self-check', followupCategoryIds, {
            prefer_different: true,
            require_different: false,
            prior_plan: currentSelfCheckPlan,
            source: 'dashboard_chunk_mode_next'
        }).done(function (plan) {
            if (hasChunkPlanTarget(plan)) {
                checkFollowupDifferentPlan = plan;
                updateCheckFollowupActionCopy();
                return;
            }
            resolveChunkPlanForMode('self-check', followupCategoryIds, {
                prefer_different: false,
                require_different: false,
                source: 'dashboard_chunk_mode_next'
            }).done(function (fallbackPlan) {
                checkFollowupDifferentPlan = hasChunkPlanTarget(fallbackPlan) ? fallbackPlan : null;
                updateCheckFollowupActionCopy();
            });
        });

        resolveRecommendedFollowupPlan('self-check', followupCategoryIds).done(function (plan) {
            checkFollowupNextPlan = plan;
            updateCheckFollowupActionCopy();
        });

        refreshRecommendation().always(function () {
            resolveRecommendedFollowupPlan('self-check', followupCategoryIds).done(function (plan) {
                checkFollowupNextPlan = plan;
                updateCheckFollowupActionCopy();
            });
        });
    }

    function launchDashboardPlan(plan) {
        const target = (plan && typeof plan === 'object') ? plan : null;
        if (!target || !target.mode) {
            return;
        }
        const mode = normalizeProgressMode(target.mode);
        if (!mode) {
            return;
        }
        const targetCategoryIds = uniqueIntList(target.category_ids || []).filter(function (id) {
            return !isCategoryIgnored(id);
        });
        const fallbackCategoryIds = uniqueIntList(state.category_ids || []).filter(function (id) {
            return !isCategoryIgnored(id);
        });
        const categoryIds = targetCategoryIds.length ? targetCategoryIds : fallbackCategoryIds;
        const sessionWordIds = uniqueIntList(target.session_word_ids || []);
        const source = String(target.source || 'dashboard_chunk_results');

        hideResultsChunkActions();

        if (mode === 'self-check') {
            const mainApi = window.LLFlashcards && window.LLFlashcards.Main;
            const popupOpen = $('body').hasClass('ll-tools-flashcard-open') ||
                $('#ll-tools-flashcard-popup:visible, #ll-tools-flashcard-quiz-popup:visible').length > 0;
            const closePromise = (popupOpen && mainApi && typeof mainApi.closeFlashcard === 'function')
                ? Promise.resolve(mainApi.closeFlashcard())
                : Promise.resolve();
            closePromise.catch(function () {
                return undefined;
            }).finally(function () {
                startCheckFlow(categoryIds, {
                    sessionWordIds: sessionWordIds,
                    source: source
                });
            });
            return;
        }

        if (!sessionWordIds.length && categoryIds.length) {
            resolveChunkPlanForMode(mode, categoryIds, {
                prefer_different: false,
                require_different: false,
                source: source
            }).done(function (resolvedPlan) {
                const resolvedCategoryIds = uniqueIntList((resolvedPlan && resolvedPlan.category_ids) || categoryIds).filter(function (id) {
                    return !isCategoryIgnored(id);
                });
                const resolvedSessionWordIds = uniqueIntList((resolvedPlan && resolvedPlan.session_word_ids) || []);
                startFlashcards(mode, {
                    categoryIds: resolvedCategoryIds.length ? resolvedCategoryIds : categoryIds,
                    sessionWordIds: resolvedSessionWordIds,
                    source: source
                });
            });
            return;
        }

        startFlashcards(mode, {
            categoryIds: categoryIds,
            sessionWordIds: sessionWordIds,
            source: source
        });
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
            if (!cat || !Object.prototype.hasOwnProperty.call(cat, 'gender_supported')) { return false; }
            return parseBooleanFlag(cat.gender_supported);
        });
    }

    function updateGenderButtonVisibility() {
        const wordsetEnabled = isGenderEnabledForWordset();
        if ($goalGenderMode.length) {
            $goalGenderMode.toggleClass('ll-study-btn--hidden', !wordsetEnabled);
            $goalGenderMode.attr('aria-hidden', wordsetEnabled ? 'false' : 'true');
        }

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
        target.genderVisualConfig = (genderConfig.visual_config && typeof genderConfig.visual_config === 'object')
            ? genderConfig.visual_config
            : {};
        const fallbackMin = parseInt(target.genderMinCount, 10) || 0;
        const minCount = parseInt(genderConfig.min_count, 10) || fallbackMin || 2;
        target.genderMinCount = minCount;
    }

    function ensureWordsForCategory(catId) {
        const cid = parseInt(catId, 10);
        if (!cid) { return $.Deferred().resolve([]).promise(); }
        const requestedWordsetId = getCurrentWordsetId();
        if (categoryWordsMatchWordset(wordsByCategory[cid], requestedWordsetId)) {
            return $.Deferred().resolve(wordsByCategory[cid]).promise();
        }
        const requestEpoch = wordsRequestEpoch;
        return $.post(ajaxUrl, {
            action: 'll_user_study_fetch_words',
            nonce: nonce,
            wordset_id: requestedWordsetId,
            category_ids: [cid]
        }).then(function (res) {
            if (requestEpoch !== wordsRequestEpoch || requestedWordsetId !== getCurrentWordsetId()) {
                return [];
            }
            if (res && res.success && res.data && res.data.words_by_category) {
                wordsByCategory = Object.assign({}, wordsByCategory, res.data.words_by_category);
                const nextRows = wordsByCategory[cid] || [];
                return categoryWordsMatchWordset(nextRows, requestedWordsetId) ? nextRows : [];
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

    function getWordProgressCoverage(word) {
        if (!word || typeof word !== 'object') { return 0; }
        return Math.max(0, parseInt(word.progress_total_coverage, 10) || 0);
    }

    function getWordProgressStage(word) {
        if (!word || typeof word !== 'object') { return 0; }
        return Math.max(0, parseInt(word.progress_stage, 10) || 0);
    }

    function hasCategoryExposure(categoryId) {
        const cid = parseInt(categoryId, 10) || 0;
        if (!cid) { return false; }
        const progress = categoryProgress[cid];
        if (!progress || typeof progress !== 'object') { return false; }
        return (parseInt(progress.exposure_total, 10) || 0) > 0;
    }

    function buildCheckQueue(categoryIds, allowedWordIds) {
        const ids = shuffleItems(toIntList(categoryIds));
        const allowedLookup = {};
        toIntList(allowedWordIds || []).forEach(function (id) {
            allowedLookup[id] = true;
        });
        const hasAllowed = Object.keys(allowedLookup).length > 0;
        const seen = {};
        const practiced = [];
        const mixed = [];
        const unseen = [];
        const fallback = [];

        ids.forEach(function (cid) {
            const cat = getCategoryById(cid);
            const optionType = getCategoryOptionType(cat);
            const promptType = getCategoryPromptType(cat);
            const catLabel = getCategoryLabel(cat);
            const categorySeenBefore = hasCategoryExposure(cid);
            const words = shuffleItems(getCategoryWords(cid) || []);
            words.forEach(function (word) {
                const wordId = parseInt(word && word.id, 10) || 0;
                if (!wordId || seen[wordId]) { return; }
                if (hasAllowed && !allowedLookup[wordId]) { return; }
                seen[wordId] = true;
                const coverage = getWordProgressCoverage(word);
                const stage = getWordProgressStage(word);
                const item = {
                    word: word,
                    wordId: wordId,
                    categoryId: cid,
                    categoryLabel: catLabel,
                    optionType: optionType,
                    promptType: promptType,
                    coverage: coverage,
                    stage: stage,
                    categorySeenBefore: categorySeenBefore
                };
                fallback.push(item);
                if (coverage > 0) {
                    practiced.push(item);
                    return;
                }
                if (categorySeenBefore) {
                    mixed.push(item);
                    return;
                }
                unseen.push(item);
            });
        });

        const queue = [];
        let practicedRun = 0;
        let unseenIndex = 0;
        const practicedPool = shuffleItems(practiced.concat(mixed));
        const unseenPool = shuffleItems(unseen);

        while (practicedPool.length || unseenIndex < unseenPool.length) {
            if (practicedPool.length) {
                queue.push(practicedPool.shift());
                practicedRun++;
            }
            if (unseenIndex < unseenPool.length && (practicedRun >= CHECK_SPRINKLE_EVERY || !practicedPool.length)) {
                queue.push(unseenPool[unseenIndex]);
                unseenIndex++;
                practicedRun = 0;
            }
            if (!practicedPool.length && unseenIndex < unseenPool.length && queue.length < 8) {
                queue.push(unseenPool[unseenIndex]);
                unseenIndex++;
            }
        }

        return queue.length ? queue : fallback;
    }

    function getTrackerModeForSession() {
        return 'self-check';
    }

    function updateCheckActionLabels() {
        if ($checkRestart.length) {
            setRedoActionButtonContent($checkRestart, i18n.checkRestart || 'Repeat');
        }
    }

    function getSelfCheckRenderMessages() {
        return {
            emptyLabel: i18n.checkEmpty || 'No words available for this check.',
            playAudioLabel: i18n.playAudio || 'Play audio',
            playAudioType: i18n.playAudioType || 'Play %s recording',
            recordingIsolation: i18n.recordingIsolation || 'Isolation',
            recordingIntroduction: i18n.recordingIntroduction || 'Introduction',
            recordingsLabel: i18n.recordingsLabel || 'Recordings',
            selfCheckPlayAudio: i18n.playAudio || 'Play audio'
        };
    }

    function renderCheckPrompt(item) {
        const word = (item && item.word) ? item.word : null;
        const messages = getSelfCheckRenderMessages();
        if (selfCheckShared && typeof selfCheckShared.renderSelfCheckPromptDisplay === 'function') {
            selfCheckShared.renderSelfCheckPromptDisplay($checkPrompt, word, Object.assign({}, messages, { displayType: 'image' }));
            return;
        }
        if (selfCheckShared && typeof selfCheckShared.renderPromptDisplay === 'function') {
            selfCheckShared.renderPromptDisplay($checkPrompt, 'image', word, messages);
        }
    }

    function renderCheckAnswer(item) {
        const word = (item && item.word) ? item.word : null;
        const messages = getSelfCheckRenderMessages();
        const options = Object.assign({}, messages, {
            displayType: 'image',
            recordingTypes: ['isolation', 'introduction'],
            isolationFallbackToAnyAudio: false,
            introductionFallbackToAnyAudio: false,
            messages: messages,
            onPlayAudio: function (_audioUrl, buttonEl) {
                handleRecordingButtonClick($(buttonEl), buttonEl);
            }
        });

        if (selfCheckShared && typeof selfCheckShared.renderSelfCheckAnswerDisplay === 'function') {
            return selfCheckShared.renderSelfCheckAnswerDisplay($checkAnswer, word, options);
        }
        if (selfCheckShared && typeof selfCheckShared.renderPromptDisplay === 'function') {
            selfCheckShared.renderPromptDisplay($checkAnswer, 'image', word, messages);
        }
        return [];
    }

    function getCheckIsolationAudioUrl(word) {
        if (selfCheckShared && typeof selfCheckShared.getIsolationAudioUrl === 'function') {
            return selfCheckShared.getIsolationAudioUrl(word, { fallbackToAnyAudio: false });
        }
        return '';
    }

    function clearCheckAdvanceTimer() {
        if (!checkSession) { return; }
        if (checkSession.advanceTimer) {
            clearTimeout(checkSession.advanceTimer);
            checkSession.advanceTimer = null;
        }
    }

    function bumpCheckToken() {
        if (!checkSession) { return 0; }
        checkSession.token = (parseInt(checkSession.token, 10) || 0) + 1;
        return checkSession.token;
    }

    function isCheckTokenCurrent(token) {
        return !!(checkSession && token && checkSession.token === token);
    }

    function getCurrentCheckItem() {
        if (!checkSession || !Array.isArray(checkSession.items)) { return null; }
        return checkSession.items[checkSession.index] || null;
    }

    function formatCheckSummaryFromCounts(counts) {
        const safe = counts || {};
        const unsure = Math.max(0, parseInt(safe.idk, 10) || 0);
        const wrong = Math.max(0, parseInt(safe.wrong, 10) || 0);
        const close = Math.max(0, parseInt(safe.close, 10) || 0);
        const right = Math.max(0, parseInt(safe.right, 10) || 0);
        const unsureOrWrong = unsure + wrong;
        const crossIcon = buildCheckSummaryIconHtml('wrong');
        const closeIcon = buildCheckSummaryIconHtml('close');
        const rightIcon = buildCheckSummaryIconHtml('right');
        return formatTemplate(
            i18n.checkSummaryCompactHtml || 'Self check complete: %1$s %2$d, %3$s %4$d, %5$s %6$d.',
            [crossIcon, unsureOrWrong, closeIcon, close, rightIcon, right]
        );
    }

    function buildCheckSummaryIconHtml(choiceValue) {
        const $icon = buildCheckActionIcon(choiceValue);
        if (!$icon || !$icon.length) { return ''; }
        $icon.addClass('ll-study-check-summary-icon').attr('aria-hidden', 'true');
        return $('<div>').append($icon).html();
    }

    function buildCheckActionIcon(choiceValue) {
        const key = String(choiceValue || '').toLowerCase();
        const isCross = (key === 'idk' || key === 'wrong');
        const isCheck = (key === 'know' || key === 'right');
        const isThink = (key === 'think');
        const isClose = (key === 'close');
        if (!isCross && !isCheck && !isThink && !isClose) { return null; }

        const $icon = $('<span>', { class: 'll-study-check-icon', 'aria-hidden': 'true' });
        if (isCross) {
            $icon.append($(`
                <svg width="64" height="64" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                    <line x1="16" y1="16" x2="48" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line>
                    <line x1="48" y1="16" x2="16" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line>
                </svg>
            `));
            return $icon;
        }

        if (isThink) {
            $icon.append($(`
                <svg width="64" height="64" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                    <g fill="currentColor">
                        <circle cx="22" cy="18" r="7.5"></circle>
                        <circle cx="33" cy="14" r="8.5"></circle>
                        <circle cx="44" cy="18" r="7.5"></circle>
                        <circle cx="17" cy="27" r="7"></circle>
                        <circle cx="49" cy="27" r="7"></circle>
                        <circle cx="23" cy="35" r="7.5"></circle>
                        <circle cx="33" cy="36" r="8.5"></circle>
                        <circle cx="43" cy="34" r="7.5"></circle>
                        <circle cx="33" cy="26" r="11"></circle>
                        <circle cx="44.6" cy="51.2" r="4.3"></circle>
                        <circle cx="54.8" cy="56.6" r="3.4"></circle>
                    </g>
                </svg>
            `));
            return $icon;
        }

        if (isClose) {
            $icon.append($(`
                <svg width="64" height="64" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="32" cy="32" r="24" fill="none" stroke="currentColor" stroke-width="6"></circle>
                    <path fill="currentColor" fill-rule="evenodd" d="M32 8 A24 24 0 1 1 31.999 8 Z M32 32 L32 8 A24 24 0 0 0 8 32 Z"></path>
                </svg>
            `));
            return $icon;
        }

        $icon.append($(`
            <svg width="64" height="64" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                <polyline points="14,34 28,46 50,18" fill="none" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"></polyline>
            </svg>
        `));
        return $icon;
    }

    function renderCheckActions(phase) {
        if (!$checkActions.length) { return; }
        const normalized = String(phase || '').toLowerCase() === 'result' ? 'result' : 'confidence';
        const options = normalized === 'result' ? CHECK_ACTIONS_RESULT : CHECK_ACTIONS_CONFIDENCE;
        $checkActions.empty();

        options.forEach(function (opt) {
            const label = i18n[opt.labelKey] || opt.fallback || '';
            const $btn = $('<button>', {
                type: 'button',
                class: 'll-study-btn ll-study-check-btn ' + opt.className,
                'data-ll-check-choice': opt.value
            });
            const $icon = buildCheckActionIcon(opt.value);
            if ($icon && $icon.length) {
                $btn.append($icon);
            }
            $btn.append($('<span>', { class: 'll-study-check-btn-text', text: label }));
            $btn.appendTo($checkActions);
        });
    }

    function setCheckActionsDisabled(disabled) {
        if (!$checkActions.length) { return; }
        $checkActions.find('button').prop('disabled', !!disabled).toggleClass('disabled', !!disabled);
    }

    function playCheckIsolationAudio(item) {
        const row = item || getCurrentCheckItem();
        const word = row && row.word ? row.word : null;
        const url = getCheckIsolationAudioUrl(word);
        if (!url) {
            return Promise.resolve(false);
        }
        stopCurrentAudio();
        return new Promise(function (resolve) {
            const audio = new Audio(url);
            currentAudio = audio;
            currentAudioButton = null;

            let done = false;
            const timeoutId = setTimeout(function () {
                finish(true);
            }, CHECK_AUDIO_TIMEOUT_MS);
            const finish = function (played) {
                if (done) { return; }
                done = true;
                clearTimeout(timeoutId);
                audio.removeEventListener('ended', onEnded);
                audio.removeEventListener('error', onError);
                try {
                    if (!audio.paused) {
                        audio.pause();
                    }
                } catch (_) { /* no-op */ }
                if (currentAudio === audio) {
                    currentAudio = null;
                    currentAudioButton = null;
                }
                resolve(!!played);
            };
            const onEnded = function () { finish(true); };
            const onError = function () { finish(false); };
            audio.addEventListener('ended', onEnded);
            audio.addEventListener('error', onError);

            if (audio.play) {
                audio.play().catch(function () {
                    finish(false);
                });
            } else {
                finish(false);
            }
        });
    }

    function recordCheckResult(confidence, result) {
        if (!checkSession || !checkSession.items || !checkSession.items.length) { return; }
        const item = checkSession.items[checkSession.index];
        if (!item || !item.wordId) { return; }

        const confidenceKey = String(confidence || '').toLowerCase();
        const resultKey = String(result || '').toLowerCase();
        let bucket = 'wrong';
        let isCorrect = false;
        let hadWrongBefore = true;

        if (confidenceKey === 'idk') {
            bucket = 'idk';
            isCorrect = false;
            hadWrongBefore = true;
        } else if (resultKey === 'right') {
            bucket = 'right';
            isCorrect = true;
            hadWrongBefore = false;
        } else if (resultKey === 'close') {
            bucket = 'close';
            isCorrect = true;
            hadWrongBefore = true;
        } else {
            bucket = 'wrong';
            isCorrect = false;
            hadWrongBefore = true;
        }

        try {
            const tracker = getProgressTracker();
            if (tracker && typeof tracker.trackWordOutcome === 'function') {
                tracker.trackWordOutcome({
                    mode: getTrackerModeForSession(),
                    wordId: item.wordId,
                    categoryId: item.categoryId,
                    categoryName: item.categoryLabel || '',
                    wordsetId: state.wordset_id,
                    isCorrect: isCorrect,
                    hadWrongBefore: hadWrongBefore,
                    payload: {
                        self_check_confidence: confidenceKey,
                        self_check_result: resultKey || bucket,
                        self_check_bucket: bucket,
                        forced_prompt: 'image'
                    }
                });
            }
        } catch (_) { /* no-op */ }

        checkSession.categoryStats = (checkSession.categoryStats && typeof checkSession.categoryStats === 'object')
            ? checkSession.categoryStats
            : {};
        checkSession.counts = (checkSession.counts && typeof checkSession.counts === 'object')
            ? checkSession.counts
            : { idk: 0, wrong: 0, close: 0, right: 0 };

        const categoryId = parseInt(item.categoryId, 10) || 0;
        if (categoryId) {
            if (!checkSession.categoryStats[categoryId]) {
                checkSession.categoryStats[categoryId] = {
                    total: 0,
                    idk: 0,
                    wrong: 0,
                    close: 0,
                    right: 0,
                    confidence_known: 0
                };
            }
            const stats = checkSession.categoryStats[categoryId];
            stats.total += 1;
            if (bucket === 'idk') { stats.idk += 1; }
            if (bucket === 'wrong') { stats.wrong += 1; }
            if (bucket === 'close') { stats.close += 1; }
            if (bucket === 'right') { stats.right += 1; }
            if (confidenceKey === 'think' || confidenceKey === 'know') {
                stats.confidence_known += 1;
            }
        }

        if (Object.prototype.hasOwnProperty.call(checkSession.counts, bucket)) {
            checkSession.counts[bucket] += 1;
        }

        if (bucket === 'idk') {
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
        $checkAnswer.show().empty();
        $checkActions.show();
        if ($checkCard.length) {
            $checkCard.show();
        }
        setCheckFlipped(false);
        $checkComplete.hide();
        hideCheckFollowupActions();
    }

    function closeCheckPanel() {
        if (!$checkPanel.length) { return; }
        $checkPanel.removeClass('is-active').attr('aria-hidden', 'true');
        unlockCheckViewportScroll();
        updateCheckActionLabels();
        clearCheckAdvanceTimer();
        $checkCategory.text('');
        $checkProgress.text('');
        $checkPrompt.empty().show();
        $checkAnswer.empty().show();
        $checkActions.show();
        if ($checkCard.length) {
            $checkCard.show();
        }
        setCheckFlipped(false);
        $checkComplete.hide();
        hideCheckFollowupActions();
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
        clearCheckAdvanceTimer();
        bumpCheckToken();

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
                tracker.trackWordExposure({
                    mode: getTrackerModeForSession(),
                    wordId: item.wordId,
                    categoryId: item.categoryId,
                    categoryName: item.categoryLabel || '',
                    wordsetId: state.wordset_id,
                    payload: {
                        forced_prompt: 'image',
                        check_phase: 'confidence'
                    }
                });
            }
        } catch (_) { /* no-op */ }

        renderCheckPrompt(item);
        renderCheckAnswer(item);
        setCheckFlipped(false);
        checkSession.phase = 'confidence';
        checkSession.pendingConfidence = '';
        renderCheckActions('confidence');
        setCheckActionsDisabled(false);
    }

    function showCheckComplete() {
        if (!checkSession) { return; }
        const total = checkSession.items.length || 0;
        $checkSummary.html(formatCheckSummaryFromCounts(checkSession.counts || {}));
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
        persistSelfCheckKnownCategories(checkSession);
        renderCheckFollowupActions();
    }

    function handleCheckConfidenceChoice(choice) {
        if (!checkSession || checkSession.phase !== 'confidence') { return; }
        const confidence = String(choice || '').toLowerCase();
        if (['idk', 'think', 'know'].indexOf(confidence) === -1) { return; }
        const item = getCurrentCheckItem();
        if (!item) { return; }
        const token = checkSession.token;
        checkSession.pendingConfidence = confidence;
        checkSession.phase = 'result';
        renderCheckActions('result');
        setCheckActionsDisabled(true);
        if (confidence === 'idk') {
            setCheckFlipped(false);
            playCheckIsolationAudio(item).then(function (played) {
                if (!isCheckTokenCurrent(token) || !checkSession) { return; }
                clearCheckAdvanceTimer();
                checkSession.advanceTimer = setTimeout(function () {
                    if (!isCheckTokenCurrent(token)) { return; }
                    recordCheckResult('idk', 'idk');
                }, played ? CHECK_AUTO_ADVANCE_DELAY_MS : CHECK_AUDIO_FAIL_DELAY_MS);
            });
            return;
        }

        renderCheckAnswer(item);
        setCheckFlipped(true);
        playCheckIsolationAudio(item).then(function (played) {
            if (!isCheckTokenCurrent(token) || !checkSession) { return; }
            const enableResultChoices = function () {
                if (!isCheckTokenCurrent(token) || !checkSession) { return; }
                setCheckActionsDisabled(false);
            };
            if (played) {
                enableResultChoices();
                return;
            }
            setTimeout(enableResultChoices, CHECK_AUDIO_FAIL_DELAY_MS);
        });
    }

    function handleCheckResultChoice(choice) {
        if (!checkSession || checkSession.phase !== 'result') { return; }
        const result = String(choice || '').toLowerCase();
        if (['right', 'close', 'wrong'].indexOf(result) === -1) { return; }
        const confidence = String(checkSession.pendingConfidence || '').toLowerCase();
        if (!confidence || confidence === 'idk') {
            recordCheckResult('think', result);
            return;
        }
        recordCheckResult(confidence, result);
    }

    function getKnownCategoryIdsFromSelfCheck(session) {
        const src = session || {};
        const stats = (src.categoryStats && typeof src.categoryStats === 'object') ? src.categoryStats : {};
        const known = [];
        Object.keys(stats).forEach(function (key) {
            const cid = parseInt(key, 10) || 0;
            if (!cid) { return; }
            const row = stats[cid] || {};
            const total = Math.max(0, parseInt(row.total, 10) || 0);
            if (total < 2) { return; }
            const right = Math.max(0, parseInt(row.right, 10) || 0);
            const close = Math.max(0, parseInt(row.close, 10) || 0);
            const wrong = Math.max(0, parseInt(row.wrong, 10) || 0);
            const idk = Math.max(0, parseInt(row.idk, 10) || 0);
            const weighted = right + (close * 0.6) - (wrong * 0.8) - (idk * 1.15);
            const ratio = (right + close) / total;
            if (weighted >= 0.75 && ratio >= 0.6 && idk === 0) {
                known.push(cid);
            }
        });
        return uniqueIntList(known);
    }

    function persistSelfCheckKnownCategories(session) {
        const src = (session && typeof session === 'object') ? session : null;
        if (!src || src.persistedKnownCategories) {
            return;
        }
        src.persistedKnownCategories = true;

        const testedCategoryIds = uniqueIntList(Object.keys(src.categoryStats || {}).map(function (key) {
            return parseInt(key, 10) || 0;
        }).filter(Boolean));
        if (!testedCategoryIds.length) {
            return;
        }

        const testedLookup = {};
        testedCategoryIds.forEach(function (id) {
            testedLookup[id] = true;
        });
        const knownCategoryIds = getKnownCategoryIdsFromSelfCheck(src);
        const keepExisting = uniqueIntList(goals.placement_known_category_ids || []).filter(function (id) {
            return !testedLookup[id];
        });
        const nextKnownCategoryIds = uniqueIntList(keepExisting.concat(knownCategoryIds));
        if (areIntListsEqual(nextKnownCategoryIds, goals.placement_known_category_ids || [])) {
            return;
        }

        goals.placement_known_category_ids = nextKnownCategoryIds;
        renderGoalsControls();
        saveGoalsNow();
    }

    function startCheckFlow(categoryIds, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const ids = toIntList(categoryIds || state.category_ids).filter(function (id) {
            return !isCategoryIgnored(id);
        });
        const sessionWordIds = uniqueIntList(opts.sessionWordIds || opts.session_word_ids || []);
        if (!ids.length) {
            ensureCategoriesSelected();
            return;
        }
        syncProgressTrackerContext(getTrackerModeForSession(), ids);
        updateCheckActionLabels();
        if ($checkStart.length) {
            $checkStart.prop('disabled', true).addClass('loading');
        }

        ensureWordsForCategories(ids).always(function () {
            const items = buildCheckQueue(ids, sessionWordIds);
            if (!items.length) {
                alert(i18n.checkEmpty || 'No words available for this check.');
                closeCheckPanel();
                return;
            }
            setActiveChunkLaunch('self-check', ids, sessionWordIds, opts.source || 'dashboard');
            checkSession = {
                mode: 'self-check',
                items: items,
                index: 0,
                unknownIds: [],
                unknownLookup: {},
                categoryIds: ids,
                categoryStats: {},
                counts: { idk: 0, wrong: 0, close: 0, right: 0 },
                phase: 'confidence',
                pendingConfidence: '',
                token: 0,
                advanceTimer: null,
                persistedKnownCategories: false,
                sessionWordIds: sessionWordIds.slice()
            };
            openCheckPanel();
            showCheckItem();
        }).always(function () {
            if ($checkStart.length) {
                $checkStart.prop('disabled', false).removeClass('loading');
            }
        });
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
        const requestEpoch = wordsRequestEpoch;
        const requestedWordsetId = getCurrentWordsetId();
        $.post(ajaxUrl, {
            action: 'll_user_study_fetch_words',
            nonce: nonce,
            wordset_id: requestedWordsetId,
            category_ids: ids
        }).done(function (res) {
            if (requestEpoch !== wordsRequestEpoch || requestedWordsetId !== getCurrentWordsetId()) {
                return;
            }
            if (res && res.success && res.data && res.data.words_by_category) {
                wordsByCategory = res.data.words_by_category;
                renderWords();
            }
            refreshRecommendation();
        });
    }

    function reloadForWordset(wordsetId) {
        bumpWordsRequestEpoch();
        const reloadToken = ++wordsetReloadToken;
        $.post(ajaxUrl, {
            action: 'll_user_study_bootstrap',
            nonce: nonce,
            wordset_id: wordsetId
        }).done(function (res) {
            if (reloadToken !== wordsetReloadToken) { return; }
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
            saveStateDebounced();
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
        const launchSource = String(options.source || 'dashboard');
        const requestedFromOptions = uniqueIntList(options.category_ids || options.categoryIds || []);
        const requestedCategoryIds = (requestedFromOptions.length
            ? requestedFromOptions
            : uniqueIntList(state.category_ids || [])
        ).filter(function (id) {
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
        let quizMode = normalizeQuizMode(mode) || 'practice';
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
            flashData.launchContext = 'dashboard';
            flashData.launch_context = 'dashboard';
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

            const activeWordsetId = getCurrentWordsetId();
            const resolvedWordsetSpec = findWordsetSlug(activeWordsetId) || (activeWordsetId > 0 ? String(activeWordsetId) : '');
            flashData.wordset = resolvedWordsetSpec;
            flashData.wordsetIds = activeWordsetId > 0 ? [activeWordsetId] : [];
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
            setActiveChunkLaunch(quizMode, requestedCategoryIds, sessionWordIds, launchSource);
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

    function startModeWithChunk(mode, categoryIds, triggerButton) {
        const requestedMode = normalizeProgressMode(mode);
        const requestedCategoryIds = uniqueIntList(categoryIds || state.category_ids).filter(function (id) {
            return !isCategoryIgnored(id);
        });
        if (!requestedMode) { return; }
        if (!ensureCategoriesSelected(requestedCategoryIds)) { return; }

        const $btn = (triggerButton && triggerButton.length) ? triggerButton : $();
        if ($btn.length) {
            $btn.prop('disabled', true).addClass('loading');
        }

        resolveChunkPlanForMode(requestedMode, requestedCategoryIds, {
            prefer_different: true,
            source: 'dashboard_chunk_manual'
        }).done(function (plan) {
            const launchPlan = Object.assign({}, plan, {
                mode: requestedMode,
                source: 'dashboard_chunk_manual'
            });
            launchDashboardPlan(launchPlan);
        }).always(function () {
            if ($btn.length) {
                $btn.prop('disabled', false).removeClass('loading');
            }
        });
    }

    $wordsetSelect.on('change', function () {
        const newId = parseInt($(this).val(), 10) || 0;
        state.wordset_id = newId;
        state.category_ids = [];
        state.starred_word_ids = [];
        state.star_mode = normalizeStarMode(state.star_mode);
        bumpWordsRequestEpoch();
        wordsByCategory = {};
        renderWords();
        if (newId > 0 && goals.preferred_wordset_ids.indexOf(newId) === -1) {
            goals.preferred_wordset_ids.push(newId);
            goals.preferred_wordset_ids = uniqueIntList(goals.preferred_wordset_ids);
            saveGoalsDebounced();
        }
        setStudyPrefsGlobal();
        reloadForWordset(newId);
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
        const modeRaw = $(this).data('mode') || $(this).attr('data-mode') || 'practice';
        const mode = normalizeQuizMode(modeRaw) || 'practice';
        const categoryIds = getSelectedCategoryIdsFromUI();
        const launchCategoryIds = categoryIds.length ? categoryIds : state.category_ids;
        startModeWithChunk(mode, launchCategoryIds, $(this));
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
            launchDashboardPlan({
                mode: next.mode,
                category_ids: categoryIds,
                session_word_ids: next.session_word_ids || [],
                source: 'dashboard_chunk_next'
            });
        });
    }

    if ($checkStart.length) {
        $checkStart.on('click', function () {
            const categoryIds = getSelectedCategoryIdsFromUI();
            startModeWithChunk('self-check', categoryIds.length ? categoryIds : state.category_ids, $(this));
        });
    }

    if ($checkActions.length) {
        $checkActions.on('click', '[data-ll-check-choice]', function () {
            const choice = String($(this).attr('data-ll-check-choice') || '').toLowerCase();
            if (!choice || !checkSession) { return; }
            if (checkSession.phase === 'result') {
                handleCheckResultChoice(choice);
                return;
            }
            handleCheckConfidenceChoice(choice);
        });
    }

    if ($checkFollowupDifferent.length) {
        $checkFollowupDifferent.on('click', function (e) {
            e.preventDefault();
            if (!checkFollowupDifferentPlan) { return; }
            launchDashboardPlan(checkFollowupDifferentPlan);
        });
    }

    if ($checkFollowupNext.length) {
        $checkFollowupNext.on('click', function (e) {
            e.preventDefault();
            if (!checkFollowupNextPlan) {
                const currentCategoryIds = getFollowupCategoryIds(
                    checkSession && Array.isArray(checkSession.categoryIds) ? checkSession.categoryIds : []
                );
                refreshRecommendation().always(function () {
                    resolveRecommendedFollowupPlan('self-check', currentCategoryIds).done(function (plan) {
                        checkFollowupNextPlan = plan;
                        updateCheckFollowupActionCopy();
                        if (checkFollowupNextPlan) {
                            launchDashboardPlan(checkFollowupNextPlan);
                        }
                    });
                });
                return;
            }
            launchDashboardPlan(checkFollowupNextPlan);
        });
    }

    if ($checkRestart.length) {
        $checkRestart.on('click', function () {
            const ids = checkSession && Array.isArray(checkSession.categoryIds)
                ? checkSession.categoryIds
                : state.category_ids;
            const sessionWordIds = (checkSession && Array.isArray(checkSession.sessionWordIds))
                ? checkSession.sessionWordIds
                : [];
            startCheckFlow(ids, {
                sessionWordIds: sessionWordIds,
                source: 'dashboard_chunk_restart'
            });
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

    if ($resultsSameChunk.length) {
        $resultsSameChunk.on('click', function (e) {
            e.preventDefault();
            if (!resultsSameChunkPlan) { return; }
            launchDashboardPlan(resultsSameChunkPlan);
        });
    }

    if ($resultsDifferentChunk.length) {
        $resultsDifferentChunk.on('click', function (e) {
            e.preventDefault();
            if (!resultsDifferentChunkPlan) { return; }
            launchDashboardPlan(resultsDifferentChunkPlan);
        });
    }

    if ($resultsNextChunk.length) {
        $resultsNextChunk.on('click', function (e) {
            e.preventDefault();
            if (!resultsNextChunkPlan) {
                const currentMode = normalizeProgressMode(resultsSameChunkPlan && resultsSameChunkPlan.mode) || 'practice';
                const currentCategoryIds = getFollowupCategoryIds(
                    resultsSameChunkPlan && Array.isArray(resultsSameChunkPlan.category_ids)
                        ? resultsSameChunkPlan.category_ids
                        : []
                );
                refreshRecommendation().always(function () {
                    resolveRecommendedFollowupPlan(currentMode, currentCategoryIds).done(function (plan) {
                        resultsNextChunkPlan = plan;
                        updateResultsChunkActionCopy();
                        if (resultsNextChunkPlan) {
                            launchDashboardPlan(resultsNextChunkPlan);
                        }
                    });
                });
                return;
            }
            launchDashboardPlan(resultsNextChunkPlan);
        });
    }

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
        if ($resultsActions.length && $resultsActions.is(':visible')) {
            const currentMode = normalizeProgressMode(resultsSameChunkPlan && resultsSameChunkPlan.mode) || '';
            const currentCategoryIds = getFollowupCategoryIds(
                resultsSameChunkPlan && Array.isArray(resultsSameChunkPlan.category_ids)
                    ? resultsSameChunkPlan.category_ids
                    : []
            );
            resolveRecommendedFollowupPlan(currentMode, currentCategoryIds).done(function (plan) {
                resultsNextChunkPlan = plan;
                updateResultsChunkActionCopy();
            });
        }
        if ($checkFollowup.length && $checkFollowup.is(':visible')) {
            const currentCategoryIds = getFollowupCategoryIds(
                checkSession && Array.isArray(checkSession.categoryIds) ? checkSession.categoryIds : []
            );
            resolveRecommendedFollowupPlan('self-check', currentCategoryIds).done(function (plan) {
                checkFollowupNextPlan = plan;
                updateCheckFollowupActionCopy();
            });
        }
    });

    $(document).on('lltools:flashcard-results-shown', function (_evt, detail) {
        const info = detail || {};
        renderResultsChunkActions(normalizeProgressMode(info.mode || ''));
    });

    $(document).on('lltools:flashcard-opened', function () {
        if ($checkPanel.length && $checkPanel.hasClass('is-active')) {
            closeCheckPanel();
        }
        stopCurrentAudio();
        hideResultsChunkActions();
    });

    $(document).on('lltools:flashcard-closed', function () {
        stopCurrentAudio();
        restoreDashboardScrollIfNeeded();
        hideResultsChunkActions();
    });

    // Initial render
    ensureSelectedCategoriesRespectGoals();
    renderWordsets();
    renderGoalsControls();
    renderCategories();
    renderWords();
    renderStarModeToggle();
    renderTransitionToggle();
    updateCheckActionLabels();
    renderNextActivity();
    updateGenderButtonVisibility();
    hideResultsChunkActions();
    setStudyPrefsGlobal();
    syncProgressTrackerContext();

    window.LLToolsStudyDashboard = window.LLToolsStudyDashboard || {};
    window.LLToolsStudyDashboard.startSelfCheck = function (categoryIds) {
        startModeWithChunk('self-check', Array.isArray(categoryIds) ? categoryIds : state.category_ids);
    };
})(jQuery);
