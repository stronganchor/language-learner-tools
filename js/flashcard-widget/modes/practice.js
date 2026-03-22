(function (root) {
    'use strict';

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Modes = root.LLFlashcards.Modes || {};

    const State = (root.LLFlashcards.State = root.LLFlashcards.State || {});
    const Selection = (root.LLFlashcards.Selection = root.LLFlashcards.Selection || {});
    const FlashcardOptions = root.FlashcardOptions || {};
    const Results = (root.LLFlashcards.Results = root.LLFlashcards.Results || {});
    const Util = (root.LLFlashcards.Util = root.LLFlashcards.Util || {});
    const STATES = State.STATES || {};
    const PRACTICE_PROMPT_ORDER = ['question', 'isolation', 'introduction', 'sentence', 'in-sentence'];
    const PRACTICE_CATEGORY_PREFETCH_BATCH_SIZE = 2;
    const PRACTICE_CATEGORY_PREFETCH_WAIT_MS = 2600;
    const PRACTICE_CATEGORY_PREFETCH_TRIGGER_ROUND = 2;
    const pendingCategoryLoads = {};

    function normalizeStarMode(mode) {
        const val = (mode || '').toString();
        return (val === 'only' || val === 'normal' || val === 'weighted') ? val : 'normal';
    }

    function getSessionWordIds() {
        const data = root.llToolsFlashcardsData || {};
        const raw = Array.isArray(data.sessionWordIds)
            ? data.sessionWordIds
            : (Array.isArray(data.session_word_ids) ? data.session_word_ids : []);
        return raw
            .map(function (id) { return parseInt(id, 10) || 0; })
            .filter(function (id) { return id > 0; });
    }

    function hasSessionWordFilter() {
        return getSessionWordIds().length > 0;
    }

    function getPracticeCategoryPrefetchBatchSize() {
        return hasSessionWordFilter() ? 4 : PRACTICE_CATEGORY_PREFETCH_BATCH_SIZE;
    }

    function getPracticeCategoryPrefetchTriggerRound() {
        return hasSessionWordFilter() ? 1 : PRACTICE_CATEGORY_PREFETCH_TRIGGER_ROUND;
    }

    function getPracticeCategoryLoadedFloor() {
        return hasSessionWordFilter() ? 2 : 1;
    }

    function getWordsetCacheKey() {
        const data = root.llToolsFlashcardsData || {};
        const ws = (typeof data.wordset !== 'undefined') ? data.wordset : '';
        const fallback = (typeof data.wordsetFallback === 'undefined') ? true : !!data.wordsetFallback;
        const sessionRaw = Array.isArray(data.sessionWordIds)
            ? data.sessionWordIds
            : (Array.isArray(data.session_word_ids) ? data.session_word_ids : []);
        const sessionKey = sessionRaw
            .map(function (id) { return parseInt(id, 10) || 0; })
            .filter(function (id) { return id > 0; })
            .sort(function (a, b) { return a - b; })
            .join(',');
        return String(ws || '') + '|' + (fallback ? '1' : '0') + '|' + (sessionKey || 'all');
    }

    function getCategoryCacheKey(categoryName) {
        return getWordsetCacheKey() + '::' + String(categoryName || '');
    }

    function isCategoryLoaded(categoryName, loader) {
        const name = String(categoryName || '');
        if (!name) {
            return false;
        }
        const api = loader || root.FlashcardLoader;
        if (api && typeof api.isCategoryLoaded === 'function') {
            return !!api.isCategoryLoaded(name);
        }
        const rows = State.wordsByCategory && State.wordsByCategory[name];
        if (Array.isArray(rows) && rows.length > 0) {
            return true;
        }
        const loaded = api && Array.isArray(api.loadedCategories) ? api.loadedCategories : [];
        const key = getCategoryCacheKey(name);
        return loaded.indexOf(key) !== -1 || loaded.indexOf(name) !== -1;
    }

    function isCategoryLoading(categoryName, loader) {
        const name = String(categoryName || '').trim();
        const api = loader || root.FlashcardLoader;
        if (!name || !api) {
            return false;
        }
        if (typeof api.isCategoryLoading === 'function') {
            return !!api.isCategoryLoading(name);
        }
        return false;
    }

    function getRemainingCategoryOrder() {
        const completed = (State.completedCategories && typeof State.completedCategories === 'object')
            ? State.completedCategories
            : {};
        const seen = {};
        const ordered = [];
        const sources = [
            Array.isArray(State.categoryNames) ? State.categoryNames : [],
            Array.isArray(State.initialCategoryNames) ? State.initialCategoryNames : []
        ];

        sources.forEach(function (list) {
            (Array.isArray(list) ? list : []).forEach(function (rawName) {
                const name = String(rawName || '').trim();
                if (!name || seen[name] || completed[name]) {
                    return;
                }
                seen[name] = true;
                ordered.push(name);
            });
        });

        return ordered;
    }

    function clearPendingCategoryLoads() {
        Object.keys(pendingCategoryLoads).forEach(function (key) {
            delete pendingCategoryLoads[key];
        });
    }

    function queueCategoryLoad(categoryName, loader) {
        const name = String(categoryName || '').trim();
        const api = loader || root.FlashcardLoader;
        if (!name || !api || typeof api.loadResourcesForCategory !== 'function') {
            return Promise.resolve('');
        }

        const cacheKey = getCategoryCacheKey(name);
        if (pendingCategoryLoads[cacheKey]) {
            return pendingCategoryLoads[cacheKey];
        }
        if (isCategoryLoaded(name, api)) {
            return Promise.resolve(cacheKey);
        }
        if (isCategoryLoading(name, api)) {
            const waitForInFlight = new Promise(function (resolve) {
                const poll = function () {
                    if (isCategoryLoaded(name, api) || !isCategoryLoading(name, api)) {
                        resolve(cacheKey);
                        return;
                    }
                    setTimeout(poll, 80);
                };
                poll();
            }).finally(function () {
                delete pendingCategoryLoads[cacheKey];
            });
            pendingCategoryLoads[cacheKey] = waitForInFlight;
            return waitForInFlight;
        }

        const promise = new Promise(function (resolve) {
            try {
                api.loadResourcesForCategory(name, function () {
                    resolve(cacheKey);
                }, { earlyCallback: true });
            } catch (_) {
                resolve(cacheKey);
            }
        }).finally(function () {
            delete pendingCategoryLoads[cacheKey];
        });

        pendingCategoryLoads[cacheKey] = promise;
        return promise;
    }

    function getPendingCategoryNames(loader) {
        const api = loader || root.FlashcardLoader;
        return getRemainingCategoryOrder().filter(function (categoryName) {
            return !isCategoryLoaded(categoryName, api);
        });
    }

    function hasPendingOrUnloadedCategories(loader) {
        if (Object.keys(pendingCategoryLoads).length > 0) {
            return true;
        }
        const pendingNames = getPendingCategoryNames(loader);
        if (pendingNames.length > 0) {
            return true;
        }
        return getRemainingCategoryOrder().some(function (categoryName) {
            return isCategoryLoading(categoryName, loader);
        });
    }

    function maybeQueueCategoryPrefetch(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const loader = opts.loader || root.FlashcardLoader;
        if (!loader || typeof loader.loadResourcesForCategory !== 'function') {
            return Promise.resolve([]);
        }

        const pendingNames = getPendingCategoryNames(loader);
        if (!pendingNames.length) {
            return Promise.resolve([]);
        }

        const loadedRemainingCount = getRemainingCategoryOrder().filter(function (categoryName) {
            return isCategoryLoaded(categoryName, loader);
        }).length;
        const roundsInCurrentCategory = Math.max(0, parseInt(State.currentCategoryRoundCount, 10) || 0);
        const shouldQueue = !!opts.force ||
            loadedRemainingCount <= getPracticeCategoryLoadedFloor() ||
            roundsInCurrentCategory >= getPracticeCategoryPrefetchTriggerRound();

        if (!shouldQueue) {
            return Promise.resolve([]);
        }

        const count = Math.max(1, parseInt(opts.count, 10) || getPracticeCategoryPrefetchBatchSize());
        const promises = pendingNames.slice(0, count).map(function (categoryName) {
            return queueCategoryLoad(categoryName, loader);
        });
        return Promise.all(promises).catch(function () {
            return [];
        });
    }

    function waitForPendingCategories(loader, timeoutMs) {
        maybeQueueCategoryPrefetch({
            loader: loader,
            force: true
        });

        const pending = Object.values(pendingCategoryLoads || {});
        if (!pending.length) {
            return Promise.resolve(false);
        }

        return new Promise(function (resolve) {
            let settled = false;
            const finish = function () {
                if (settled) {
                    return;
                }
                settled = true;
                const hasLoadedWords = getRemainingCategoryOrder().some(function (categoryName) {
                    const rows = State.wordsByCategory && State.wordsByCategory[categoryName];
                    return Array.isArray(rows) && rows.length > 0;
                });
                resolve(!!hasLoadedWords);
            };

            pending.forEach(function (promise) {
                Promise.resolve(promise).then(finish).catch(finish);
            });

            setTimeout(finish, Math.max(700, parseInt(timeoutMs, 10) || PRACTICE_CATEGORY_PREFETCH_WAIT_MS));
        });
    }

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
        if (State && State.starModeOverride) {
            return normalizeStarMode(State.starModeOverride);
        }
        const prefs = root.llToolsStudyPrefs || {};
        const modeFromPrefs = prefs.starMode || prefs.star_mode;
        const modeFromFlash = (root.llToolsFlashcardsData && (root.llToolsFlashcardsData.starMode || root.llToolsFlashcardsData.star_mode)) || null;
        const mode = modeFromPrefs || modeFromFlash || 'normal';
        return normalizeStarMode(mode);
    }

    function isStarred(wordId) {
        if (!wordId) { return false; }
        const lookup = getStarredLookup();
        return !!lookup[wordId];
    }

    function incrementForcedReplayCount(wordId) {
        if (!wordId) return;
        const map = (State.practiceForcedReplays = State.practiceForcedReplays || {});
        const key = String(wordId);
        map[key] = (map[key] || 0) + 1;
    }

    function findWordAndCategoryById(wordId) {
        if (!wordId || !State || !State.wordsByCategory) return null;
        const idStr = String(wordId);
        for (const catName in State.wordsByCategory) {
            if (!Object.prototype.hasOwnProperty.call(State.wordsByCategory, catName)) continue;
            const list = State.wordsByCategory[catName];
            if (!Array.isArray(list)) continue;
            const word = list.find(function (w) { return String(w.id) === idStr; });
            if (word) {
                return { word, categoryName: catName };
            }
        }
        return null;
    }

    function normalizeRecordingType(type) {
        return String(type || '')
            .trim()
            .toLowerCase()
            .replace(/[\s_]+/g, '-')
            .replace(/[^a-z0-9-]/g, '');
    }

    function isUserLoggedIn() {
        const data = root.llToolsFlashcardsData || {};
        return !!data.isUserLoggedIn;
    }

    function sortRecordingTypes(types) {
        const seen = {};
        const extras = [];
        PRACTICE_PROMPT_ORDER.forEach(function (type) {
            const key = normalizeRecordingType(type);
            if (key) {
                seen[key] = false;
            }
        });

        (Array.isArray(types) ? types : []).forEach(function (type) {
            const key = normalizeRecordingType(type);
            if (!key || Object.prototype.hasOwnProperty.call(seen, key) && seen[key] === true) {
                return;
            }
            if (Object.prototype.hasOwnProperty.call(seen, key)) {
                seen[key] = true;
                return;
            }
            if (extras.indexOf(key) === -1) {
                extras.push(key);
            }
        });

        const ordered = [];
        PRACTICE_PROMPT_ORDER.forEach(function (type) {
            const key = normalizeRecordingType(type);
            if (key && seen[key] === true) {
                ordered.push(key);
            }
        });
        extras.sort();
        return ordered.concat(extras);
    }

    function getAvailableRecordingTypes(word) {
        if (!word || typeof word !== 'object') {
            return [];
        }

        const explicit = Array.isArray(word.practice_recording_types)
            ? word.practice_recording_types
            : [];
        if (explicit.length) {
            return sortRecordingTypes(explicit);
        }

        const files = Array.isArray(word.audio_files) ? word.audio_files : [];
        const collected = files.map(function (entry) {
            return entry && entry.recording_type;
        });
        return sortRecordingTypes(collected);
    }

    function getCorrectRecordingTypes(word) {
        if (!word || typeof word !== 'object' || !Array.isArray(word.practice_correct_recording_types)) {
            return [];
        }
        return sortRecordingTypes(word.practice_correct_recording_types);
    }

    function setCorrectRecordingTypes(word, types) {
        if (!word || typeof word !== 'object') {
            return;
        }
        word.practice_correct_recording_types = sortRecordingTypes(types);
    }

    function getPracticeExposureCount(word) {
        if (!word || typeof word !== 'object') {
            return 0;
        }

        const raw = parseInt(word.practice_exposure_count, 10);
        return Number.isFinite(raw) && raw > 0 ? raw : 0;
    }

    function normalizeRecordingTypesInOrder(types) {
        const seen = {};
        const ordered = [];

        (Array.isArray(types) ? types : []).forEach(function (type) {
            const key = normalizeRecordingType(type);
            if (!key || seen[key]) {
                return;
            }
            seen[key] = true;
            ordered.push(key);
        });

        return ordered;
    }

    function getRecordingTextForType(word, recordingType) {
        const key = normalizeRecordingType(recordingType);
        if (!word || typeof word !== 'object' || !key) {
            return '';
        }

        const textMap = (word.recording_texts_by_type && typeof word.recording_texts_by_type === 'object')
            ? word.recording_texts_by_type
            : null;
        if (textMap) {
            const entries = Object.keys(textMap);
            for (let i = 0; i < entries.length; i += 1) {
                const entryKey = normalizeRecordingType(entries[i]);
                if (entryKey === key) {
                    return String(textMap[entries[i]] || '').trim();
                }
            }
        }

        const files = Array.isArray(word.audio_files) ? word.audio_files : [];
        for (let i = 0; i < files.length; i += 1) {
            const entry = files[i] || {};
            if (normalizeRecordingType(entry.recording_type) !== key) {
                continue;
            }
            return String(entry.recording_text || '').trim();
        }

        return '';
    }

    function selectAudioEntryForTypes(word, preferredTypes) {
        if (!word || typeof word !== 'object') {
            return null;
        }

        const files = Array.isArray(word.audio_files) ? word.audio_files : [];
        const orderedTypes = normalizeRecordingTypesInOrder(preferredTypes);
        const preferredSpeaker = parseInt(word.preferred_speaker_user_id, 10) || 0;
        const hasUrl = function (entry) {
            return !!(entry && typeof entry.url === 'string' && entry.url.trim() !== '');
        };

        for (let i = 0; i < orderedTypes.length; i += 1) {
            const key = orderedTypes[i];

            if (preferredSpeaker > 0) {
                const sameSpeaker = files.find(function (entry) {
                    return hasUrl(entry)
                        && normalizeRecordingType(entry.recording_type) === key
                        && (parseInt(entry.speaker_user_id, 10) || 0) === preferredSpeaker;
                });
                if (sameSpeaker) {
                    return {
                        type: key,
                        url: String(sameSpeaker.url).trim(),
                        recordingText: String(sameSpeaker.recording_text || '').trim()
                    };
                }
            }

            const anySpeaker = files.find(function (entry) {
                return hasUrl(entry) && normalizeRecordingType(entry.recording_type) === key;
            });
            if (anySpeaker) {
                return {
                    type: key,
                    url: String(anySpeaker.url).trim(),
                    recordingText: String(anySpeaker.recording_text || '').trim()
                };
            }
        }

        const fallback = files.find(hasUrl);
        if (fallback) {
            return {
                type: normalizeRecordingType(fallback.recording_type) || (orderedTypes[0] || ''),
                url: String(fallback.url).trim(),
                recordingText: String(fallback.recording_text || '').trim()
            };
        }

        const directAudio = typeof word.audio === 'string' ? word.audio.trim() : '';
        if (!directAudio) {
            return null;
        }

        const fallbackType = orderedTypes[0] || '';
        return {
            type: fallbackType,
            url: directAudio,
            recordingText: getRecordingTextForType(word, fallbackType)
        };
    }

    function resolvePracticePromptAudio(word) {
        const availableTypes = getAvailableRecordingTypes(word);
        const correctTypes = isUserLoggedIn() ? getCorrectRecordingTypes(word) : [];
        let selectedType = '';

        if (isUserLoggedIn() && availableTypes.length) {
            const progressIndex = Math.max(getPracticeExposureCount(word), correctTypes.length);
            selectedType = availableTypes[progressIndex % availableTypes.length] || '';
        }

        if (!selectedType && isUserLoggedIn()) {
            selectedType = availableTypes.find(function (type) {
                return correctTypes.indexOf(type) === -1;
            }) || '';
        }

        if (!selectedType) {
            selectedType = PRACTICE_PROMPT_ORDER.find(function (type) {
                return availableTypes.indexOf(type) !== -1;
            }) || availableTypes[0] || '';
        }

        const preferredOrder = [];
        if (selectedType) {
            preferredOrder.push(selectedType);
        }
        availableTypes.forEach(function (type) {
            if (preferredOrder.indexOf(type) === -1) {
                preferredOrder.push(type);
            }
        });

        const entry = selectAudioEntryForTypes(word, preferredOrder);
        if (entry && entry.type) {
            selectedType = entry.type;
        }

        return {
            selectedType: selectedType,
            entry: entry
        };
    }

    function markRecordingTypeCorrect(word) {
        const type = normalizeRecordingType(word && word.__practiceRecordingType);
        if (!word || !type) {
            return;
        }

        const correctTypes = getCorrectRecordingTypes(word);
        if (correctTypes.indexOf(type) === -1) {
            correctTypes.push(type);
            setCorrectRecordingTypes(word, correctTypes);
        }
    }

    function initialize() {
        State.isLearningMode = false;
        State.isListeningMode = false;
        State.completedCategories = {};
        State.categoryRepetitionQueues = {};
        State.practiceForcedReplays = {};
        clearPendingCategoryLoads();
        return true;
    }

    function queueForRepetition(targetWord, options) {
        const categoryName = State.currentCategoryName;
        if (!categoryName || !targetWord) return;
        const queue = (State.categoryRepetitionQueues[categoryName] = State.categoryRepetitionQueues[categoryName] || []);
        const force = !!(options && options.force);

        const starMode = getStarMode();
        // If it's already queued, upgrade the entry to a forced replay (used for wrong answers)
        const existingIndex = queue.findIndex(item => item.wordData.id === targetWord.id);
        if (existingIndex !== -1) {
            if (force) {
                queue[existingIndex].forceReplay = true;
            }
            return;
        }

        const starredLookup = getStarredLookup();
        const applyStarBias = starMode !== 'normal';
        const isStarredWord = applyStarBias && !!starredLookup[targetWord.id];

        // Avoid endlessly re-queuing starred words once they've hit their allowed plays
        State.starPlayCounts = State.starPlayCounts || {};
        const plays = State.starPlayCounts[targetWord.id] || 0;
        const maxUses = (applyStarBias && isStarredWord) ? 2 : 1;
        if (!force && applyStarBias && isStarredWord && plays >= maxUses) {
            return;
        }

        const base = State.categoryRoundCount[categoryName] || 0;
        const offset = (applyStarBias && isStarred(targetWord.id))
            ? ((Util && typeof Util.randomInt === 'function') ? Util.randomInt(2, 3) : (Math.floor(Math.random() * 2) + 2)) // delay slightly to avoid immediate repeat
            : ((Util && typeof Util.randomInt === 'function') ? Util.randomInt(2, 4) : (Math.floor(Math.random() * 3) + 2));
        queue.push({
            wordData: targetWord,
            reappearRound: base + offset,
            forceReplay: force
        });
    }

    function onCorrectAnswer(ctx) {
        if (!ctx || !ctx.targetWord) return;
        markRecordingTypeCorrect(ctx.targetWord);
        if (State.wrongIndexes.length === 0) return;
        queueForRepetition(ctx.targetWord, { force: true });
    }

    function onWrongAnswer(ctx) {
        if (!ctx || !ctx.targetWord) return;
        incrementForcedReplayCount(ctx.targetWord.id);
        queueForRepetition(ctx.targetWord, { force: true });
    }

    function onFirstRoundStart() {
        maybeQueueCategoryPrefetch({
            loader: root.FlashcardLoader
        });
        return true;
    }

    function selectTargetWord() {
        FlashcardOptions.calculateNumberOfOptions(State.wrongIndexes, State.isFirstRound, State.currentCategoryName);
        const picked = Selection.selectTargetWordAndCategory();
        const starMode = getStarMode();
        if (picked && starMode === 'weighted' && isStarred(picked.id)) {
            queueForRepetition(picked);
        }
        maybeQueueCategoryPrefetch({
            loader: root.FlashcardLoader
        });
        return picked;
    }

    function handleNoTarget(ctx) {
        const context = (ctx && typeof ctx === 'object') ? ctx : {};
        const loader = context.FlashcardLoader || root.FlashcardLoader;
        const restartAfterPendingLoad = function () {
            if (context.Dom && typeof context.Dom.showLoading === 'function') {
                try { context.Dom.showLoading(); } catch (_) { /* no-op */ }
            }
            waitForPendingCategories(loader, PRACTICE_CATEGORY_PREFETCH_WAIT_MS).then(function () {
                if (!State.widgetActive) {
                    return;
                }
                if (typeof context.startQuizRound === 'function') {
                    context.startQuizRound();
                }
            });
            return true;
        };

        if (State.isFirstRound) {
            const hasWords = (State.categoryNames || []).some(name => {
                const words = State.wordsByCategory && State.wordsByCategory[name];
                return Array.isArray(words) && words.length > 0;
            });
            if (!hasWords) {
                if (hasPendingOrUnloadedCategories(loader)) {
                    return restartAfterPendingLoad();
                }
                if (context && typeof context.showLoadingError === 'function') {
                    context.showLoadingError();
                }
                return true;
            }
        }

        const queues = State.categoryRepetitionQueues || {};
        const queuedCategories = [];
        const queuedIds = new Set();
        Object.keys(queues).forEach(function (cat) {
            const list = queues[cat];
            if (Array.isArray(list) && list.length) {
                queuedCategories.push(cat);
                list.forEach(function (item) {
                    const id = item && item.wordData && item.wordData.id;
                    if (id) queuedIds.add(String(id));
                });
            }
        });

        const outstanding = (State.practiceForcedReplays = State.practiceForcedReplays || {});
        Object.keys(outstanding).forEach(function (id) {
            if ((outstanding[id] || 0) <= 0) return;
            const key = String(id);
            if (queuedIds.has(key)) return;
            const found = findWordAndCategoryById(id);
            if (!found) return;
            const prevCategory = State.currentCategoryName;
            State.currentCategoryName = found.categoryName;
            queueForRepetition(found.word, { force: true });
            State.currentCategoryName = prevCategory;
            queuedIds.add(key);
            if (!queuedCategories.includes(found.categoryName)) {
                queuedCategories.push(found.categoryName);
            }
        });

        if (queuedCategories.length) {
            const lastShownKey = String(State.lastWordShownId || '');
            const queuedOnlyLastShown = !!lastShownKey && queuedIds.size === 1 && queuedIds.has(lastShownKey);
            const hasBridgeWord = !!(
                queuedOnlyLastShown &&
                Selection &&
                typeof Selection.hasPracticeBridgeWordAvailable === 'function' &&
                Selection.hasPracticeBridgeWordAvailable(State.lastWordShownId, State.currentCategoryName)
            );

            if (queuedOnlyLastShown && !hasBridgeWord) {
                if (ctx && typeof ctx.updatePracticeModeProgress === 'function') {
                    ctx.updatePracticeModeProgress();
                }
                State.transitionTo && State.transitionTo(STATES.SHOWING_RESULTS, 'Practice replay deadlock avoided');
                Results.showResults && Results.showResults();
                return true;
            }

            State.completedCategories = State.completedCategories || {};
            queuedCategories.forEach(function (cat) {
                State.completedCategories[cat] = false;
                if (!State.categoryNames.includes(cat)) {
                    State.categoryNames.push(cat);
                }
            });
            State.isFirstRound = false;
            maybeQueueCategoryPrefetch({
                loader: loader,
                force: true
            });
            if (context && typeof context.startQuizRound === 'function') {
                setTimeout(function () { context.startQuizRound(); }, 0);
            }
            return true;
        }

        if (hasPendingOrUnloadedCategories(loader)) {
            return restartAfterPendingLoad();
        }

        if (context && typeof context.updatePracticeModeProgress === 'function') {
            context.updatePracticeModeProgress();
        }
        State.transitionTo && State.transitionTo(STATES.SHOWING_RESULTS, 'Quiz complete');
        Results.showResults && Results.showResults();
        return true;
    }

    function beforeOptionsFill() {
        return true;
    }

    function configureTargetAudio(target) {
        if (!target) return true;
        const promptType = State.currentPromptType || 'audio';
        if (promptType !== 'audio') {
            delete target.__practiceRecordingType;
            delete target.__practiceRecordingText;
            return true;
        }

        const resolved = resolvePracticePromptAudio(target);
        if (resolved.entry && resolved.entry.url) {
            target.audio = resolved.entry.url;
        }

        if (resolved.selectedType) {
            target.__practiceRecordingType = resolved.selectedType;
            target.__practiceRecordingText = resolved.entry && resolved.entry.recordingText
                ? resolved.entry.recordingText
                : getRecordingTextForType(target, resolved.selectedType);
        } else {
            delete target.__practiceRecordingType;
            delete target.__practiceRecordingText;
        }

        return true;
    }

    root.LLFlashcards.Modes.Practice = {
        initialize,
        getChoiceCount: function () { return null; },
        recordAnswerResult: function () { },
        onCorrectAnswer,
        onWrongAnswer,
        onFirstRoundStart,
        selectTargetWord,
        handleNoTarget,
        beforeOptionsFill,
        configureTargetAudio,
        queueCategoryLoad
    };
})(window);
