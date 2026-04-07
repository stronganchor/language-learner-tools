(function (root, $) {
    'use strict';

    root.LLFlashcards = root.LLFlashcards || {};
    if (root.LLFlashcards.ProgressTracker) {
        return;
    }

    const MAX_QUEUE = 2500;
    const MAX_BATCH_SIZE = 200;
    const MODE_ORDER = ['learning', 'practice', 'listening', 'gender', 'self-check'];
    const ALLOWED_TYPES = {
        word_outcome: true,
        word_exposure: true,
        category_study: true,
        mode_session_complete: true
    };
    const MASTERED_STAGE_THRESHOLD = 5;
    const MASTERED_CLEAN_THRESHOLD = 3;
    const HARD_DIFFICULTY_THRESHOLD = 4;
    const DUE_INTERVALS_DAYS = {
        0: 0,
        1: 1,
        2: 2,
        3: 4,
        4: 7,
        5: 14,
        6: 30
    };

    let queue = [];
    let flushTimer = null;
    let inFlight = false;
    let context = {
        mode: 'practice',
        wordsetId: 0,
        categoryIds: []
    };
    let localStoreCache = null;
    let localStoreKey = '';
    let sessionStoreCache = null;
    let sessionStoreKey = '';

    function toInt(value) {
        const parsed = parseInt(value, 10);
        return parsed > 0 ? parsed : 0;
    }

    function toIntList(list) {
        const seen = {};
        return (Array.isArray(list) ? list : [])
            .map(function (item) { return toInt(item); })
            .filter(function (id) {
                if (!id || seen[id]) { return false; }
                seen[id] = true;
                return true;
            });
    }

    function parseBool(value, fallback) {
        if (typeof value === 'boolean') { return value; }
        if (typeof value === 'number') { return value > 0; }
        if (typeof value === 'string') {
            const lowered = value.trim().toLowerCase();
            if (['1', 'true', 'yes', 'on'].indexOf(lowered) !== -1) { return true; }
            if (['0', 'false', 'no', 'off', ''].indexOf(lowered) !== -1) { return false; }
        }
        return !!fallback;
    }

    function sanitizeString(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value).trim();
    }

    function normalizeMode(mode) {
        let value = sanitizeString(mode).toLowerCase();
        if (value === 'self_check') {
            value = 'self-check';
        }
        return MODE_ORDER.indexOf(value) !== -1 ? value : 'practice';
    }

    function buildId(prefix) {
        const ts = Date.now().toString(36);
        const rand = Math.random().toString(36).slice(2, 10);
        return String(prefix || 'llp') + '-' + ts + '-' + rand;
    }

    function cloneValue(value) {
        if (!value || typeof value !== 'object') {
            return value;
        }
        try {
            return JSON.parse(JSON.stringify(value));
        } catch (_) {
            return value;
        }
    }

    function parseTimestampMs(raw, fallbackMs) {
        if (typeof raw === 'number' && isFinite(raw) && raw > 0) {
            return raw;
        }
        if (typeof raw === 'string') {
            const text = raw.trim();
            if (text !== '') {
                if (/^\d+$/.test(text)) {
                    const numeric = parseInt(text, 10);
                    if (numeric > 0) {
                        return numeric;
                    }
                }
                const parsed = Date.parse(text);
                if (isFinite(parsed) && parsed > 0) {
                    return parsed;
                }
            }
        }
        return Math.max(1, parseInt(fallbackMs, 10) || Date.now());
    }

    function isoFromMs(ms) {
        try {
            return new Date(parseTimestampMs(ms, Date.now())).toISOString();
        } catch (_) {
            return '';
        }
    }

    function mysqlUtcFromMs(ms) {
        const iso = isoFromMs(ms);
        return iso ? iso.slice(0, 19).replace('T', ' ') : '';
    }

    function getFlashData() {
        return (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData === 'object')
            ? root.llToolsFlashcardsData
            : {};
    }

    function getStudyData() {
        return (root.llToolsStudyData && typeof root.llToolsStudyData === 'object')
            ? root.llToolsStudyData
            : {};
    }

    function getRuntimeMode() {
        const flash = getFlashData();
        return sanitizeString(flash.runtimeMode || flash.runtime_mode || 'wp').toLowerCase();
    }

    function isOfflineRuntime() {
        return getRuntimeMode() === 'offline';
    }

    function canPersistLocally() {
        return isOfflineRuntime() && !!root.localStorage;
    }

    function getAjaxUrl() {
        const flash = getFlashData();
        const study = getStudyData();
        return sanitizeString(flash.ajaxurl || study.ajaxUrl || '');
    }

    function getNonce() {
        const flash = getFlashData();
        const study = getStudyData();
        return sanitizeString(flash.userStudyNonce || study.nonce || '');
    }

    function isUserLoggedIn() {
        const flash = getFlashData();
        if (typeof flash.isUserLoggedIn !== 'undefined') {
            return parseBool(flash.isUserLoggedIn, false);
        }
        return !!getNonce();
    }

    function getOfflineSyncConfig() {
        const flash = getFlashData();
        const cfg = (flash.offlineSync && typeof flash.offlineSync === 'object') ? flash.offlineSync : {};
        return {
            enabled: parseBool(cfg.enabled, false),
            ajaxUrl: sanitizeString(cfg.ajaxUrl || cfg.ajaxurl || ''),
            loginAction: sanitizeString(cfg.loginAction || ''),
            logoutAction: sanitizeString(cfg.logoutAction || ''),
            syncAction: sanitizeString(cfg.syncAction || '')
        };
    }

    function emitDocumentEvent(name, payload) {
        if (!name) {
            return;
        }
        try {
            if ($ && typeof $.fn !== 'undefined' && typeof document !== 'undefined') {
                $(document).trigger(name, [payload]);
            }
        } catch (_) {
            // Ignore event dispatch failures.
        }
    }

    function getWordsetStorageSuffix() {
        const flash = getFlashData();
        const userState = (flash.userStudyState && typeof flash.userStudyState === 'object') ? flash.userStudyState : {};
        const wordsetId = toInt(userState.wordset_id || flash.genderWordsetId || (Array.isArray(flash.wordsetIds) ? flash.wordsetIds[0] : 0));
        if (wordsetId > 0) {
            return 'wordset:' + String(wordsetId);
        }
        const slug = sanitizeString(flash.wordset || '').toLowerCase();
        return slug ? ('slug:' + slug) : 'default';
    }

    function getLocalStore() {
        if (!canPersistLocally()) {
            return null;
        }
        const key = 'lltools_offline_progress_v2::' + getWordsetStorageSuffix();
        if (localStoreCache && localStoreKey === key) {
            return localStoreCache;
        }
        localStoreKey = key;
        localStoreCache = {
            device_id: buildId('ll-device'),
            profile_id: buildId('ll-profile'),
            queue: [],
            words: {},
            study_state: {},
            last_synced_at: '',
            last_sync_error: ''
        };
        try {
            const raw = root.localStorage.getItem(key);
            if (!raw) {
                return localStoreCache;
            }
            const decoded = JSON.parse(raw);
            if (!decoded || typeof decoded !== 'object') {
                return localStoreCache;
            }
            localStoreCache.device_id = sanitizeString(decoded.device_id || '') || localStoreCache.device_id;
            localStoreCache.profile_id = sanitizeString(decoded.profile_id || '') || localStoreCache.profile_id;
            localStoreCache.queue = Array.isArray(decoded.queue) ? decoded.queue.slice(-MAX_QUEUE) : [];
            localStoreCache.words = (decoded.words && typeof decoded.words === 'object') ? decoded.words : {};
            localStoreCache.study_state = (decoded.study_state && typeof decoded.study_state === 'object') ? decoded.study_state : {};
            localStoreCache.last_synced_at = sanitizeString(decoded.last_synced_at || '');
            localStoreCache.last_sync_error = sanitizeString(decoded.last_sync_error || '');
        } catch (_) {
            localStoreCache = {
                device_id: buildId('ll-device'),
                profile_id: buildId('ll-profile'),
                queue: [],
                words: {},
                study_state: {},
                last_synced_at: '',
                last_sync_error: ''
            };
        }
        return localStoreCache;
    }

    function saveLocalStore() {
        if (!canPersistLocally()) {
            return;
        }
        const store = getLocalStore();
        if (!store) {
            return;
        }
        store.queue = queue.slice(-MAX_QUEUE);
        try {
            root.localStorage.setItem(localStoreKey, JSON.stringify(store));
        } catch (_) {
            // Ignore storage failures.
        }
    }

    function getSessionStore() {
        if (!canPersistLocally()) {
            return null;
        }
        const key = 'lltools_offline_sync_session_v1::' + getWordsetStorageSuffix();
        if (sessionStoreCache && sessionStoreKey === key) {
            return sessionStoreCache;
        }
        sessionStoreKey = key;
        sessionStoreCache = {};
        try {
            const raw = root.localStorage.getItem(key);
            if (!raw) {
                return sessionStoreCache;
            }
            const decoded = JSON.parse(raw);
            sessionStoreCache = decoded && typeof decoded === 'object' ? decoded : {};
        } catch (_) {
            sessionStoreCache = {};
        }
        return sessionStoreCache;
    }

    function saveSessionStore() {
        if (!canPersistLocally()) {
            return;
        }
        try {
            root.localStorage.setItem(sessionStoreKey, JSON.stringify(getSessionStore() || {}));
        } catch (_) {
            // Ignore storage failures.
        }
    }

    function normalizeStudyState(rawState) {
        const flash = getFlashData();
        const flashState = (flash.userStudyState && typeof flash.userStudyState === 'object') ? flash.userStudyState : {};
        const state = (rawState && typeof rawState === 'object') ? rawState : {};
        return {
            wordset_id: resolveWordsetId(state.wordset_id || state.wordsetId || flashState.wordset_id),
            category_ids: toIntList(state.category_ids || state.categoryIds || flashState.category_ids || []),
            starred_word_ids: toIntList(state.starred_word_ids || state.starredWordIds || flashState.starred_word_ids || []),
            star_mode: sanitizeString(state.star_mode || state.starMode || flashState.star_mode || 'normal') || 'normal',
            fast_transitions: parseBool(
                typeof state.fast_transitions !== 'undefined' ? state.fast_transitions : state.fastTransitions,
                parseBool(flashState.fast_transitions, false)
            )
        };
    }

    function applyStoredStudyStateToRuntime(studyState) {
        const normalized = normalizeStudyState(studyState);
        const flash = getFlashData();
        flash.userStudyState = Object.assign({}, flash.userStudyState || {}, normalized);
        flash.starredWordIds = normalized.starred_word_ids.slice();
        flash.starred_word_ids = normalized.starred_word_ids.slice();
        flash.starMode = normalized.star_mode;
        flash.star_mode = normalized.star_mode;
        flash.fastTransitions = !!normalized.fast_transitions;
        flash.fast_transitions = !!normalized.fast_transitions;
        if (root.llToolsStudyPrefs && typeof root.llToolsStudyPrefs === 'object') {
            root.llToolsStudyPrefs.starredWordIds = normalized.starred_word_ids.slice();
            root.llToolsStudyPrefs.starred_word_ids = normalized.starred_word_ids.slice();
            root.llToolsStudyPrefs.starMode = normalized.star_mode;
            root.llToolsStudyPrefs.star_mode = normalized.star_mode;
            root.llToolsStudyPrefs.fastTransitions = !!normalized.fast_transitions;
            root.llToolsStudyPrefs.fast_transitions = !!normalized.fast_transitions;
        }
        return normalized;
    }

    function saveStudyState(rawState, options) {
        const store = getLocalStore();
        const normalized = normalizeStudyState(rawState);
        if (store) {
            store.study_state = cloneValue(normalized);
            saveLocalStore();
        }
        const opts = (options && typeof options === 'object') ? options : {};
        if (!opts.skipRuntimeUpdate) {
            applyStoredStudyStateToRuntime(normalized);
        }
        return cloneValue(normalized);
    }

    function getStoredStudyState() {
        const store = getLocalStore();
        if (store && store.study_state && typeof store.study_state === 'object') {
            return cloneValue(normalizeStudyState(store.study_state));
        }
        return {};
    }

    function emitSyncStateChanged() {
        emitDocumentEvent('lltools:offline-sync-state-changed', getSyncState());
    }

    function setOfflineSyncSession(rawSession) {
        const session = (rawSession && typeof rawSession === 'object') ? rawSession : {};
        const store = getSessionStore();
        if (!store) {
            return {};
        }
        store.auth_token = sanitizeString(session.auth_token || session.authToken || session.token || '');
        store.expires_at = sanitizeString(session.expires_at || session.expiresAt || '');
        store.user = (session.user && typeof session.user === 'object') ? cloneValue(session.user) : {};
        saveSessionStore();
        emitDocumentEvent('lltools:offline-auth-context-updated', getSyncState());
        emitSyncStateChanged();
        return cloneValue(store);
    }

    function clearOfflineSyncSession() {
        const store = getSessionStore();
        if (!store) {
            return;
        }
        store.auth_token = '';
        store.expires_at = '';
        store.user = {};
        saveSessionStore();
        emitDocumentEvent('lltools:offline-auth-context-updated', getSyncState());
        emitSyncStateChanged();
    }

    function getOfflineSyncSession() {
        const store = getSessionStore();
        return store ? cloneValue(store) : {};
    }

    function hasOfflineSyncSession() {
        const session = getOfflineSyncSession();
        return !!sanitizeString(session.auth_token || '');
    }

    function categoryNameToId(name) {
        const target = sanitizeString(name);
        if (!target) {
            return 0;
        }
        const categories = Array.isArray(getFlashData().categories) ? getFlashData().categories : [];
        const lowered = target.toLowerCase();
        for (let i = 0; i < categories.length; i += 1) {
            const category = categories[i] || {};
            const catName = sanitizeString(category.name).toLowerCase();
            const catSlug = sanitizeString(category.slug).toLowerCase();
            if ((catName && catName === lowered) || (catSlug && catSlug === lowered)) {
                return toInt(category.id);
            }
        }
        return 0;
    }

    function categoryIdForWord(word) {
        if (!word || typeof word !== 'object') {
            return 0;
        }
        const explicit = toInt(word.category_id || word.categoryId);
        if (explicit > 0) {
            return explicit;
        }
        const byName = categoryNameToId(word.__categoryName || word.category_name || word.categoryName || '');
        if (byName > 0) {
            return byName;
        }
        const allCategories = Array.isArray(word.all_categories) ? word.all_categories : [];
        return allCategories.length ? categoryNameToId(allCategories[0]) : 0;
    }

    function resolveWordsetId(raw) {
        const explicit = toInt(raw);
        if (explicit > 0) {
            return explicit;
        }
        const active = toInt(context.wordsetId);
        if (active > 0) {
            return active;
        }
        const flash = getFlashData();
        const userState = flash.userStudyState || {};
        const fromState = toInt(userState.wordset_id);
        if (fromState > 0) {
            return fromState;
        }
        const ids = toIntList(flash.wordsetIds || []);
        return ids.length ? ids[0] : 0;
    }

    function resolveCategoryIds(raw) {
        const explicit = toIntList(raw);
        if (explicit.length) {
            return explicit;
        }
        if (context.categoryIds.length) {
            return context.categoryIds.slice();
        }
        const flash = getFlashData();
        const userState = flash.userStudyState || {};
        return toIntList(userState.category_ids || []);
    }

    function normalizeRecordingTypes(values) {
        const seen = {};
        return (Array.isArray(values) ? values : []).map(function (value) {
            return sanitizeString(value).toLowerCase().replace(/[\s_]+/g, '-').replace(/[^a-z0-9-]/g, '');
        }).filter(function (value) {
            if (!value || seen[value]) {
                return false;
            }
            seen[value] = true;
            return true;
        });
    }

    function progressModeColumn(mode) {
        const normalized = normalizeMode(mode);
        if (normalized === 'learning') { return 'coverage_learning'; }
        if (normalized === 'practice') { return 'coverage_practice'; }
        if (normalized === 'listening') { return 'coverage_listening'; }
        if (normalized === 'gender') { return 'coverage_gender'; }
        if (normalized === 'self-check') { return 'coverage_self_check'; }
        return 'coverage_practice';
    }

    function getDueAtForStage(stage, baseMs) {
        const clamped = Math.max(0, Math.min(6, parseInt(stage, 10) || 0));
        const days = parseInt(DUE_INTERVALS_DAYS[clamped], 10) || 0;
        const base = parseTimestampMs(baseMs, Date.now());
        if (days <= 0) {
            return mysqlUtcFromMs(base + (12 * 60 * 60 * 1000));
        }
        return mysqlUtcFromMs(base + (days * 24 * 60 * 60 * 1000));
    }

    function getLocalRow(wordId) {
        const store = getLocalStore();
        if (!store) {
            return null;
        }
        const wid = toInt(wordId);
        if (!wid || !store.words[String(wid)] || typeof store.words[String(wid)] !== 'object') {
            return null;
        }
        return store.words[String(wid)];
    }

    function isStudied(row) {
        if (!row || typeof row !== 'object') {
            return false;
        }
        return (parseInt(row.total_coverage, 10) || 0) > 0
            || (parseInt(row.correct_clean, 10) || 0) > 0
            || (parseInt(row.correct_after_retry, 10) || 0) > 0
            || (parseInt(row.incorrect, 10) || 0) > 0;
    }

    function meetsMastery(row) {
        if (!isStudied(row)) {
            return false;
        }
        if ((parseInt(row.stage, 10) || 0) < MASTERED_STAGE_THRESHOLD) {
            return false;
        }
        const required = normalizeRecordingTypes(row.practice_required_recording_types || []);
        const correct = normalizeRecordingTypes(row.practice_correct_recording_types || []);
        if (required.length) {
            const missing = required.filter(function (type) {
                return correct.indexOf(type) === -1;
            });
            if (missing.length) {
                return false;
            }
        }
        return (parseInt(row.correct_clean, 10) || 0) >= MASTERED_CLEAN_THRESHOLD;
    }

    function difficultyScore(row) {
        if (!isStudied(row)) {
            return -1000;
        }
        const incorrect = Math.max(0, parseInt(row.incorrect, 10) || 0);
        const lapses = Math.max(0, parseInt(row.lapse_count, 10) || 0);
        const clean = Math.max(0, parseInt(row.correct_clean, 10) || 0);
        const retry = Math.max(0, parseInt(row.correct_after_retry, 10) || 0);
        const stage = Math.max(0, parseInt(row.stage, 10) || 0);
        const streak = Math.max(0, parseInt(row.current_correct_streak, 10) || 0);
        return (incorrect * 3)
            + (lapses * 2)
            + Math.max(0, 2 - stage)
            - Math.min(4, clean)
            - Math.min(2, retry)
            - Math.min(4, Math.floor((streak * (streak + 1)) / 2));
    }

    function getStatus(row) {
        if (!isStudied(row)) {
            return 'new';
        }
        if (difficultyScore(row) < HARD_DIFFICULTY_THRESHOLD && (parseBool(row.mastery_unlocked, false) || meetsMastery(row))) {
            return 'mastered';
        }
        return 'studied';
    }

    function applyRowToWord(word, row) {
        if (!word || typeof word !== 'object' || !row || typeof row !== 'object') {
            return;
        }
        word.practice_required_recording_types = normalizeRecordingTypes(row.practice_required_recording_types || []);
        word.practice_correct_recording_types = normalizeRecordingTypes(row.practice_correct_recording_types || []);
        word.practice_exposure_count = Math.max(0, parseInt(row.coverage_practice, 10) || 0);
        word.progress_total_coverage = Math.max(0, parseInt(row.total_coverage, 10) || 0);
        word.progress_stage = Math.max(0, parseInt(row.stage, 10) || 0);
        word.progress_last_mode = sanitizeString(row.last_mode || 'practice');
        word.progress_last_seen_at = sanitizeString(row.last_seen_at || '');
        word.progress_status = getStatus(row);
        word.status = word.progress_status;
        word.difficulty_score = Math.max(0, difficultyScore(row));
        if (row.gender_progress && typeof row.gender_progress === 'object' && Object.keys(row.gender_progress).length) {
            word.gender_progress = cloneValue(row.gender_progress);
        }
    }

    function updateLoadedWords(wordId) {
        const row = getLocalRow(wordId);
        if (!row) {
            return;
        }
        const wid = toInt(wordId);
        const applyToCollection = function (collection) {
            if (!collection || typeof collection !== 'object') {
                return;
            }
            Object.keys(collection).forEach(function (key) {
                const words = collection[key];
                if (!Array.isArray(words)) {
                    return;
                }
                words.forEach(function (word) {
                    if (toInt(word && word.id) === wid) {
                        applyRowToWord(word, row);
                    }
                });
            });
        };

        applyToCollection(root.wordsByCategory);
        applyToCollection(root.optionWordsByCategory);
        if (root.LLFlashcards && root.LLFlashcards.State) {
            applyToCollection(root.LLFlashcards.State.wordsByCategory);
        }
        const flash = getFlashData();
        if (Array.isArray(flash.firstCategoryData)) {
            flash.firstCategoryData.forEach(function (word) {
                if (toInt(word && word.id) === wid) {
                    applyRowToWord(word, row);
                }
            });
        }
        if (flash.offlineCategoryData && typeof flash.offlineCategoryData === 'object') {
            applyToCollection(flash.offlineCategoryData);
        }
    }

    function applyLocalProgressToWords(words) {
        if (!Array.isArray(words) || !words.length || !canPersistLocally()) {
            return words;
        }
        words.forEach(function (word) {
            const row = getLocalRow(word && word.id);
            if (row) {
                applyRowToWord(word, row);
            }
        });
        return words;
    }

    function normalizeEvent(rawEvent) {
        const eventType = sanitizeString(rawEvent.event_type || rawEvent.type).toLowerCase();
        if (!ALLOWED_TYPES[eventType]) {
            return null;
        }
        const timingMs = parseTimestampMs(rawEvent.client_created_at_ms || rawEvent.client_created_at || rawEvent.created_at, Date.now());
        const word = (rawEvent.word && typeof rawEvent.word === 'object') ? rawEvent.word : null;
        const event = {
            event_uuid: sanitizeString(rawEvent.event_uuid || rawEvent.uuid || buildId('llp')).slice(0, 64),
            event_type: eventType,
            mode: normalizeMode(rawEvent.mode || context.mode || 'practice'),
            word_id: toInt(rawEvent.word_id || rawEvent.wordId || (word && word.id)),
            category_id: toInt(rawEvent.category_id || rawEvent.categoryId || (word && categoryIdForWord(word))),
            category_name: sanitizeString(rawEvent.category_name || rawEvent.categoryName || (word && word.__categoryName) || ''),
            wordset_id: resolveWordsetId(rawEvent.wordset_id || rawEvent.wordsetId),
            client_created_at: isoFromMs(timingMs),
            client_created_at_ms: timingMs,
            payload: (rawEvent.payload && typeof rawEvent.payload === 'object') ? cloneValue(rawEvent.payload) : {}
        };
        if (eventType === 'word_outcome') {
            if (typeof rawEvent.is_correct !== 'undefined') {
                event.is_correct = parseBool(rawEvent.is_correct, false);
            } else if (typeof rawEvent.isCorrect !== 'undefined') {
                event.is_correct = parseBool(rawEvent.isCorrect, false);
            } else {
                return null;
            }
            event.had_wrong_before = parseBool(rawEvent.had_wrong_before ?? rawEvent.hadWrongBefore, false);
        }
        if ((eventType === 'word_outcome' || eventType === 'word_exposure') && !event.word_id) {
            return null;
        }
        if (eventType === 'category_study' && !event.category_id && !event.category_name) {
            return null;
        }
        const store = getLocalStore();
        if (store) {
            event.device_id = store.device_id;
            event.profile_id = store.profile_id;
        }
        return event;
    }

    function applyEventToLocalProgress(event) {
        const store = getLocalStore();
        if (!store || !event || !toInt(event.word_id)) {
            return;
        }
        const wordId = toInt(event.word_id);
        const key = String(wordId);
        const eventMs = parseTimestampMs(event.client_created_at_ms || event.client_created_at, Date.now());
        const row = Object.assign({
            total_coverage: 0,
            coverage_learning: 0,
            coverage_practice: 0,
            coverage_listening: 0,
            coverage_gender: 0,
            coverage_self_check: 0,
            correct_clean: 0,
            correct_after_retry: 0,
            current_correct_streak: 0,
            mastery_unlocked: false,
            incorrect: 0,
            lapse_count: 0,
            stage: 0,
            last_mode: 'practice',
            last_seen_at: '',
            practice_required_recording_types: [],
            practice_correct_recording_types: [],
            gender_progress: {}
        }, cloneValue(store.words[key] || {}));

        row.last_mode = normalizeMode(event.mode);
        row.last_seen_at = mysqlUtcFromMs(eventMs);

        if (event.event_type === 'word_exposure') {
            row.total_coverage = Math.max(0, parseInt(row.total_coverage, 10) || 0) + 1;
            const modeColumn = progressModeColumn(row.last_mode);
            row[modeColumn] = Math.max(0, parseInt(row[modeColumn], 10) || 0) + 1;
        } else if (event.event_type === 'word_outcome') {
            const payload = (event.payload && typeof event.payload === 'object') ? event.payload : {};
            if (row.last_mode === 'gender' && payload.gender && typeof payload.gender === 'object') {
                row.gender_progress = cloneValue(payload.gender);
            }
            if (row.last_mode === 'practice') {
                const required = normalizeRecordingTypes((payload.available_recording_types || []).concat(row.practice_required_recording_types || []));
                const recordingType = normalizeRecordingTypes([payload.recording_type])[0] || '';
                if (recordingType && required.indexOf(recordingType) === -1) {
                    required.push(recordingType);
                }
                row.practice_required_recording_types = required;
                if (event.is_correct === true && recordingType) {
                    row.practice_correct_recording_types = normalizeRecordingTypes((row.practice_correct_recording_types || []).concat([recordingType]));
                }
            }
            if (row.last_mode === 'self-check') {
                const bucket = sanitizeString(payload.self_check_bucket || '').toLowerCase();
                if (bucket === 'idk') {
                    row.incorrect += 2;
                    row.lapse_count += 2;
                    row.stage = 0;
                } else if (bucket === 'wrong') {
                    row.incorrect += 1;
                    row.lapse_count += 1;
                    row.stage = Math.max(0, (parseInt(row.stage, 10) || 0) - 1);
                } else if (bucket === 'close') {
                    row.correct_after_retry += 1;
                    row.stage = Math.max(1, parseInt(row.stage, 10) || 0);
                } else if (bucket === 'right') {
                    row.correct_clean += 1;
                    row.stage = Math.max(3, Math.min(6, (parseInt(row.stage, 10) || 0) + 2));
                }
            } else if (row.last_mode === 'practice' && sanitizeString(payload.game_slug || '').toLowerCase() === 'speaking-practice') {
                const bucket = sanitizeString(payload.speaking_game_bucket || '').toLowerCase();
                if (bucket === 'wrong') {
                    row.incorrect += 1;
                } else if (bucket === 'close') {
                    row.correct_after_retry += 1;
                    row.stage = Math.max(1, parseInt(row.stage, 10) || 0);
                } else if (bucket === 'right') {
                    row.correct_clean += 1;
                    row.stage = Math.max(3, Math.min(6, (parseInt(row.stage, 10) || 0) + 2));
                }
            } else if (event.is_correct === true) {
                if (parseBool(event.had_wrong_before, false)) {
                    row.correct_after_retry += 1;
                    row.stage = Math.max(1, parseInt(row.stage, 10) || 0);
                } else {
                    row.correct_clean += 1;
                    row.stage = Math.max(0, Math.min(6, (parseInt(row.stage, 10) || 0) + 1));
                }
            } else if (event.is_correct === false) {
                row.incorrect += 1;
                row.lapse_count += 1;
                row.stage = Math.max(0, (parseInt(row.stage, 10) || 0) - 1);
            }

            if (event.is_correct === true) {
                row.current_correct_streak = Math.max(0, parseInt(row.current_correct_streak, 10) || 0) + 1;
            } else if (event.is_correct === false) {
                row.current_correct_streak = 0;
            }
            row.mastery_unlocked = meetsMastery(row);
        }

        store.words[key] = row;
        saveLocalStore();
        updateLoadedWords(wordId);
    }

    function mergeRemoteProgressRows(rows) {
        const store = getLocalStore();
        if (!store || !rows || typeof rows !== 'object') {
            return 0;
        }
        let changed = 0;
        Object.keys(rows).forEach(function (key) {
            const wordId = toInt(key);
            if (!wordId || !rows[key] || typeof rows[key] !== 'object') {
                return;
            }
            const existing = getLocalRow(wordId) || {};
            const remote = rows[key];
            const existingTs = parseTimestampMs(existing.updated_at || existing.last_seen_at, 0);
            const remoteTs = parseTimestampMs(remote.updated_at || remote.last_seen_at, 0);
            const merged = Object.assign({}, existingTs > remoteTs ? remote : existing, remoteTs >= existingTs ? remote : existing);
            merged.practice_required_recording_types = normalizeRecordingTypes((existing.practice_required_recording_types || []).concat(remote.practice_required_recording_types || []));
            merged.practice_correct_recording_types = normalizeRecordingTypes((existing.practice_correct_recording_types || []).concat(remote.practice_correct_recording_types || []));
            merged.mastery_unlocked = parseBool(existing.mastery_unlocked, false) || parseBool(remote.mastery_unlocked, false);
            store.words[String(wordId)] = merged;
            updateLoadedWords(wordId);
            changed += 1;
        });
        if (changed) {
            saveLocalStore();
        }
        return changed;
    }

    function enqueue(eventLike) {
        const event = normalizeEvent(eventLike || {});
        if (!event) {
            return null;
        }
        queue.push(event);
        if (queue.length > MAX_QUEUE) {
            queue = queue.slice(queue.length - MAX_QUEUE);
        }
        saveLocalStore();
        applyEventToLocalProgress(event);
        return event.event_uuid;
    }

    function scheduleFlush(delay) {
        const ms = Math.max(30, parseInt(delay, 10) || 1200);
        if (flushTimer) {
            clearTimeout(flushTimer);
        }
        flushTimer = setTimeout(function () {
            flushTimer = null;
            flush();
        }, ms);
    }

    function canSyncNow() {
        if (!$ || typeof $.post !== 'function') {
            return false;
        }
        if (isOfflineRuntime()) {
            const cfg = getOfflineSyncConfig();
            return !!cfg.enabled && !!cfg.ajaxUrl && !!cfg.syncAction && hasOfflineSyncSession();
        }
        return !!getAjaxUrl() && !!getNonce() && isUserLoggedIn();
    }

    function getOfflineStatePayload() {
        const flash = getFlashData();
        const stored = getStoredStudyState();
        const state = Object.assign(
            {},
            (flash.userStudyState && typeof flash.userStudyState === 'object') ? cloneValue(flash.userStudyState) : {},
            stored
        );
        const prefs = root.llToolsStudyPrefs && typeof root.llToolsStudyPrefs === 'object'
            ? root.llToolsStudyPrefs
            : {};
        if (Array.isArray(prefs.starredWordIds)) {
            state.starred_word_ids = prefs.starredWordIds.slice();
        }
        if (typeof prefs.starMode !== 'undefined') {
            state.star_mode = prefs.starMode;
        }
        if (typeof prefs.fastTransitions !== 'undefined') {
            state.fast_transitions = !!prefs.fastTransitions;
        }
        return state;
    }

    function collectOfflineWordIds() {
        const flash = getFlashData();
        const offlineCategoryData = (flash.offlineCategoryData && typeof flash.offlineCategoryData === 'object')
            ? flash.offlineCategoryData
            : {};
        const ids = [];
        const seen = {};
        Object.keys(offlineCategoryData).forEach(function (name) {
            const words = Array.isArray(offlineCategoryData[name]) ? offlineCategoryData[name] : [];
            words.forEach(function (word) {
                const wordId = toInt(word && word.id);
                if (!wordId || seen[wordId]) {
                    return;
                }
                seen[wordId] = true;
                ids.push(wordId);
            });
        });
        return ids;
    }

    function handleSyncSuccess(data) {
        const store = getLocalStore();
        if (store) {
            store.last_synced_at = isoFromMs(Date.now());
            store.last_sync_error = '';
            saveLocalStore();
        }
        if (data && typeof data === 'object') {
            if (data.state && typeof data.state === 'object') {
                saveStudyState(data.state);
            }
            if (data.progress_words && typeof data.progress_words === 'object') {
                mergeRemoteProgressRows(data.progress_words);
            }
            emitDocumentEvent('lltools:progress-updated', data);
            emitDocumentEvent('lltools:remote-sync-snapshot', data);
        }
        emitSyncStateChanged();
    }

    function handleSyncFailure(batch) {
        if (batch.length) {
            queue = batch.concat(queue);
            if (queue.length > MAX_QUEUE) {
                queue = queue.slice(queue.length - MAX_QUEUE);
            }
            saveLocalStore();
        }
        const store = getLocalStore();
        if (store) {
            store.last_sync_error = 'request_failed';
            saveLocalStore();
        }
        emitSyncStateChanged();
    }

    function postBatch(batch, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        if (isOfflineRuntime()) {
            const cfg = getOfflineSyncConfig();
            const session = getOfflineSyncSession();
            const wordIds = toIntList(opts.wordIds || opts.word_ids || collectOfflineWordIds());
            return $.post(cfg.ajaxUrl, {
                action: cfg.syncAction,
                auth_token: sanitizeString(session.auth_token || ''),
                events: JSON.stringify(batch || []),
                state: JSON.stringify(getOfflineStatePayload()),
                word_ids: JSON.stringify(wordIds)
            });
        }
        return $.post(getAjaxUrl(), {
            action: 'll_user_study_progress_batch',
            nonce: getNonce(),
            events: JSON.stringify(batch || []),
            wordset_id: resolveWordsetId(opts.wordsetId || opts.wordset_id),
            category_ids: resolveCategoryIds(opts.categoryIds || opts.category_ids)
        });
    }

    function flush(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        if (flushTimer) {
            clearTimeout(flushTimer);
            flushTimer = null;
        }
        if (!queue.length && !opts.allowEmpty) {
            return Promise.resolve({ queued: 0 });
        }
        if (inFlight) {
            return Promise.resolve({ queued: queue.length, in_flight: true });
        }
        if (!canSyncNow()) {
            if (!canPersistLocally()) {
                queue = [];
                return Promise.resolve({ queued: 0, skipped: true });
            }
            saveLocalStore();
            emitSyncStateChanged();
            return Promise.resolve({ queued: queue.length, deferred: true });
        }

        inFlight = true;
        const batch = opts.allowEmpty ? queue.slice(0, MAX_BATCH_SIZE) : queue.slice(0, MAX_BATCH_SIZE);
        queue = queue.slice(batch.length);
        saveLocalStore();
        emitSyncStateChanged();

        return new Promise(function (resolve) {
            const request = postBatch(batch, opts);
            request.done(function (res) {
                if (res && res.success && res.data) {
                    handleSyncSuccess(res.data);
                    resolve({
                        queued: queue.length,
                        data: res.data
                    });
                    return;
                }

                handleSyncFailure(batch);
                resolve({
                    queued: queue.length,
                    failed: true,
                    error: sanitizeString((res && res.data && res.data.message) || 'request_failed')
                });
            }).fail(function (_jqXHR, _textStatus, errorThrown) {
                handleSyncFailure(batch);
                resolve({
                    queued: queue.length,
                    failed: true,
                    error: sanitizeString(errorThrown || 'request_failed')
                });
            }).always(function () {
                inFlight = false;
                if (queue.length && canSyncNow()) {
                    scheduleFlush(50);
                }
                emitSyncStateChanged();
            });
        });
    }

    function syncFromServer(options) {
        return flush(Object.assign({}, options || {}, { allowEmpty: true }));
    }

    function setContext(nextContext) {
        const next = (nextContext && typeof nextContext === 'object') ? nextContext : {};
        if (typeof next.mode !== 'undefined') {
            context.mode = normalizeMode(next.mode);
        }
        if (typeof next.wordsetId !== 'undefined' || typeof next.wordset_id !== 'undefined') {
            context.wordsetId = resolveWordsetId(next.wordsetId || next.wordset_id);
        }
        if (typeof next.categoryIds !== 'undefined' || typeof next.category_ids !== 'undefined') {
            context.categoryIds = resolveCategoryIds(next.categoryIds || next.category_ids);
        }
        return {
            mode: context.mode,
            wordset_id: context.wordsetId,
            category_ids: context.categoryIds.slice()
        };
    }

    function setAuthContext(nextAuth) {
        const auth = (nextAuth && typeof nextAuth === 'object') ? nextAuth : {};
        const flash = getFlashData();
        const study = getStudyData();
        flash.ajaxurl = sanitizeString(auth.ajaxUrl || auth.ajaxurl || flash.ajaxurl || '');
        flash.userStudyNonce = sanitizeString(auth.nonce || auth.userStudyNonce || flash.userStudyNonce || '');
        flash.isUserLoggedIn = typeof auth.isUserLoggedIn === 'undefined'
            ? flash.isUserLoggedIn
            : !!auth.isUserLoggedIn;
        if (study && typeof study === 'object') {
            study.ajaxUrl = flash.ajaxurl;
            study.nonce = flash.userStudyNonce;
        }
        emitDocumentEvent('lltools:offline-auth-context-updated', getSyncState());
        emitSyncStateChanged();
        return getSyncState();
    }

    function trackWordExposure(raw) {
        const entry = raw || {};
        const word = (entry.word && typeof entry.word === 'object') ? entry.word : null;
        const wordId = toInt(entry.wordId || entry.word_id || (word && word.id));
        if (!wordId) {
            return null;
        }
        const eventId = enqueue({
            event_type: 'word_exposure',
            mode: entry.mode || context.mode,
            word: word,
            word_id: wordId,
            category_id: entry.categoryId || entry.category_id || (word && categoryIdForWord(word)),
            category_name: entry.categoryName || entry.category_name || (word && word.__categoryName) || '',
            wordset_id: entry.wordsetId || entry.wordset_id,
            payload: entry.payload || {}
        });
        if (eventId) {
            scheduleFlush(entry.flushDelay || 1400);
        }
        return eventId;
    }

    function trackWordOutcome(raw) {
        const entry = raw || {};
        const word = (entry.word && typeof entry.word === 'object') ? entry.word : null;
        const wordId = toInt(entry.wordId || entry.word_id || (word && word.id));
        const hasCorrect = (typeof entry.isCorrect !== 'undefined' || typeof entry.is_correct !== 'undefined');
        if (!wordId || !hasCorrect) {
            return null;
        }
        const eventId = enqueue({
            event_type: 'word_outcome',
            mode: entry.mode || context.mode,
            word: word,
            word_id: wordId,
            category_id: entry.categoryId || entry.category_id || (word && categoryIdForWord(word)),
            category_name: entry.categoryName || entry.category_name || (word && word.__categoryName) || '',
            wordset_id: entry.wordsetId || entry.wordset_id,
            is_correct: typeof entry.isCorrect !== 'undefined' ? entry.isCorrect : entry.is_correct,
            had_wrong_before: entry.hadWrongBefore ?? entry.had_wrong_before,
            payload: entry.payload || {}
        });
        if (eventId) {
            scheduleFlush(entry.flushDelay || 900);
            try {
                if ($ && typeof $.fn !== 'undefined' && typeof document !== 'undefined') {
                    $(document).trigger('lltools:flashcard-word-outcome-queued', [{
                        event_id: eventId,
                        mode: normalizeMode(entry.mode || context.mode),
                        word_id: wordId
                    }]);
                }
            } catch (_) { /* no-op */ }
        }
        return eventId;
    }

    function trackCategoryStudy(raw) {
        const entry = raw || {};
        const categoryId = toInt(entry.categoryId || entry.category_id || categoryNameToId(entry.categoryName || entry.category_name));
        const categoryName = sanitizeString(entry.categoryName || entry.category_name || '');
        if (!categoryId && !categoryName) {
            return null;
        }
        const payload = (entry.payload && typeof entry.payload === 'object') ? cloneValue(entry.payload) : {};
        if (typeof payload.units === 'undefined' && typeof entry.units !== 'undefined') {
            payload.units = Math.max(1, parseInt(entry.units, 10) || 1);
        }
        const eventId = enqueue({
            event_type: 'category_study',
            mode: entry.mode || context.mode || 'listening',
            category_id: categoryId,
            category_name: categoryName,
            wordset_id: entry.wordsetId || entry.wordset_id,
            payload: payload
        });
        if (eventId) {
            scheduleFlush(entry.flushDelay || 1400);
        }
        return eventId;
    }

    function trackModeSessionComplete(raw) {
        const entry = raw || {};
        const payload = (entry.payload && typeof entry.payload === 'object') ? cloneValue(entry.payload) : {};
        const categories = resolveCategoryIds(entry.categoryIds || entry.category_ids);
        if (categories.length) {
            payload.category_ids = categories.slice();
        }
        const eventId = enqueue({
            event_type: 'mode_session_complete',
            mode: entry.mode || context.mode,
            category_id: toInt(entry.categoryId || entry.category_id),
            category_name: sanitizeString(entry.categoryName || entry.category_name || ''),
            wordset_id: entry.wordsetId || entry.wordset_id,
            payload: payload
        });
        if (eventId) {
            scheduleFlush(entry.flushDelay || 800);
        }
        return eventId;
    }

    function clearQueue() {
        queue = [];
        if (flushTimer) {
            clearTimeout(flushTimer);
            flushTimer = null;
        }
        saveLocalStore();
    }

    function getSyncState() {
        const store = getLocalStore();
        const session = getOfflineSyncSession();
        const identity = getLocalIdentity();
        return {
            queued: queue.length,
            pending: queue.length,
            can_sync: canSyncNow(),
            local_persistence: canPersistLocally(),
            last_synced_at: store ? sanitizeString(store.last_synced_at || '') : '',
            last_sync_error: store ? sanitizeString(store.last_sync_error || '') : '',
            connected: hasOfflineSyncSession(),
            device_id: identity.device_id,
            profile_id: identity.profile_id,
            auth: {
                token: sanitizeString(session.auth_token || ''),
                expires_at: sanitizeString(session.expires_at || ''),
                user: session && session.user ? cloneValue(session.user) : {},
                last_sync_at: store ? sanitizeString(store.last_synced_at || '') : '',
                last_error: store ? sanitizeString(store.last_sync_error || '') : ''
            },
            auth_user: session && session.user ? cloneValue(session.user) : {}
        };
    }

    function getLocalIdentity() {
        const store = getLocalStore();
        return store ? {
            device_id: sanitizeString(store.device_id || ''),
            profile_id: sanitizeString(store.profile_id || '')
        } : {
            device_id: '',
            profile_id: ''
        };
    }

    if (canPersistLocally()) {
        const store = getLocalStore();
        queue = store && Array.isArray(store.queue) ? store.queue.slice(-MAX_QUEUE) : [];
    }

    if (typeof document !== 'undefined' && document.addEventListener) {
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                flush();
            }
        });
    }
    if (root && typeof root.addEventListener === 'function') {
        root.addEventListener('online', function () {
            if (queue.length || (isOfflineRuntime() && hasOfflineSyncSession())) {
                syncFromServer();
            }
        });
    }

    root.LLFlashcards.ProgressTracker = {
        setContext: setContext,
        setAuthContext: setAuthContext,
        setOfflineSyncSession: setOfflineSyncSession,
        clearOfflineSyncSession: clearOfflineSyncSession,
        getOfflineSyncSession: getOfflineSyncSession,
        setOfflineSyncAuth: setOfflineSyncSession,
        clearOfflineSyncAuth: clearOfflineSyncSession,
        flush: flush,
        flushSoon: scheduleFlush,
        syncFromServer: syncFromServer,
        clearQueue: clearQueue,
        getQueueSize: function () { return queue.length; },
        getSyncState: getSyncState,
        getOfflineSyncState: getSyncState,
        getLocalIdentity: getLocalIdentity,
        getStoredStudyState: getStoredStudyState,
        saveStudyState: saveStudyState,
        canPersistLocally: canPersistLocally,
        canSyncNow: canSyncNow,
        applyLocalProgressToWords: applyLocalProgressToWords,
        hydrateWords: applyLocalProgressToWords,
        decorateWordsForLocalProgress: applyLocalProgressToWords,
        mergeRemoteProgressRows: mergeRemoteProgressRows,
        normalizeMode: normalizeMode,
        categoryNameToId: categoryNameToId,
        categoryIdForWord: categoryIdForWord,
        trackWordExposure: trackWordExposure,
        trackWordOutcome: trackWordOutcome,
        trackCategoryStudy: trackCategoryStudy,
        trackModeSessionComplete: trackModeSessionComplete
    };
})(window, window.jQuery);
