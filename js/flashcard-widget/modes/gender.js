(function (root) {
    'use strict';

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Modes = root.LLFlashcards.Modes || {};

    const State = (root.LLFlashcards.State = root.LLFlashcards.State || {});
    const Selection = (root.LLFlashcards.Selection = root.LLFlashcards.Selection || {});
    const Dom = (root.LLFlashcards.Dom = root.LLFlashcards.Dom || {});
    const Effects = (root.LLFlashcards.Effects = root.LLFlashcards.Effects || {});
    const Results = (root.LLFlashcards.Results = root.LLFlashcards.Results || {});
    const FlashcardAudio = root.FlashcardAudio || {};
    const Util = (root.LLFlashcards.Util = root.LLFlashcards.Util || {});
    const STATES = State.STATES || {};

    const STORAGE_PREFIX = 'lltools_gender_progress_v1';
    const CHUNK_SIZE = 12;
    const INTRO_GAP_MS = 650;
    const INTRO_WORD_GAP_MS = 550;
    const INTRO_REPLAY_GAP_MS = 220;
    const COUNTDOWN_STEP_MS = 900;
    const INTRO_WATCHDOG_MS = 15000;

    const LEVEL_ONE = 1;
    const LEVEL_TWO = 2;
    const LEVEL_THREE = 3;

    let storeCache = null;
    let storeCacheKey = '';
    let session = createEmptySession();
    let round = createEmptyRoundState();

    function createEmptySession() {
        return {
            ready: false,
            level: LEVEL_ONE,
            launchSource: 'direct',
            allEligibleWords: [],
            allEligibleIds: [],
            activeWordIds: [],
            activeWordLookup: {},
            wordsById: {},
            categoryByWordId: {},
            wordState: {},
            replayQueue: [],
            introPending: false,
            introComplete: true,
            introWords: [],
            pendingPlan: null,
            currentPlan: null,
            lastWordId: 0,
            stats: {
                correct: 0,
                wrong: 0,
                dontKnow: 0,
                beforeIntroCorrect: 0,
                afterIntroCorrect: 0
            },
            resultsActions: null
        };
    }

    function createEmptyRoundState() {
        return {
            targetWordId: 0,
            sequenceToken: 0,
            timers: [],
            managedAudio: null,
            introStartedAt: 0,
            introEndedAt: 0,
            answerAt: 0,
            answerLocked: false,
            countdownEl: null
        };
    }

    function getMessages() {
        return root.llToolsFlashcardsMessages || {};
    }

    function toInt(value) {
        const parsed = parseInt(value, 10);
        return parsed > 0 ? parsed : 0;
    }

    function nowTs() {
        return Date.now();
    }

    function shuffle(list) {
        if (!Array.isArray(list)) return [];
        if (Util && typeof Util.randomlySort === 'function') {
            return Util.randomlySort(list);
        }
        return list.slice().sort(function () { return Math.random() - 0.5; });
    }

    function clamp(num, min, max) {
        const value = Number.isFinite(Number(num)) ? Number(num) : min;
        return Math.max(min, Math.min(max, value));
    }

    function normalizeLevel(raw) {
        const level = toInt(raw);
        if (level === LEVEL_ONE || level === LEVEL_TWO || level === LEVEL_THREE) {
            return level;
        }
        return LEVEL_ONE;
    }

    function getStorageKey() {
        const data = root.llToolsFlashcardsData || {};
        const userState = data.userStudyState || {};
        const wordsetId = toInt(data.genderWordsetId || userState.wordset_id || (Array.isArray(data.wordsetIds) ? data.wordsetIds[0] : 0));
        return STORAGE_PREFIX + '::wordset:' + String(wordsetId || 0);
    }

    function loadStore() {
        const key = getStorageKey();
        if (storeCache && storeCacheKey === key) return storeCache;
        storeCacheKey = key;
        storeCache = { words: {}, updated_at: nowTs() };
        const storage = root.localStorage;
        if (!storage) return storeCache;
        try {
            const raw = storage.getItem(key);
            if (!raw) return storeCache;
            const decoded = JSON.parse(raw);
            if (!decoded || typeof decoded !== 'object') return storeCache;
            const words = (decoded.words && typeof decoded.words === 'object') ? decoded.words : {};
            storeCache = {
                words: words,
                updated_at: Number(decoded.updated_at || nowTs())
            };
        } catch (_) {
            storeCache = { words: {}, updated_at: nowTs() };
        }
        return storeCache;
    }

    function saveStore() {
        const storage = root.localStorage;
        if (!storage) return;
        try {
            const key = getStorageKey();
            const snapshot = loadStore();
            snapshot.updated_at = nowTs();
            storage.setItem(key, JSON.stringify(snapshot));
        } catch (_) {
            // Ignore storage errors.
        }
    }

    function normalizeWordProgress(raw) {
        const src = (raw && typeof raw === 'object') ? raw : {};
        return {
            level: normalizeLevel(src.level),
            confidence: clamp(parseInt(src.confidence, 10) || 0, -8, 12),
            intro_seen: !!src.intro_seen,
            quick_correct_streak: Math.max(0, parseInt(src.quick_correct_streak, 10) || 0),
            level1_passes: Math.max(0, parseInt(src.level1_passes, 10) || 0),
            level1_failures: Math.max(0, parseInt(src.level1_failures, 10) || 0),
            level2_correct: Math.max(0, parseInt(src.level2_correct, 10) || 0),
            level2_wrong: Math.max(0, parseInt(src.level2_wrong, 10) || 0),
            level3_correct: Math.max(0, parseInt(src.level3_correct, 10) || 0),
            level3_wrong: Math.max(0, parseInt(src.level3_wrong, 10) || 0),
            dont_know_count: Math.max(0, parseInt(src.dont_know_count, 10) || 0),
            seen_total: Math.max(0, parseInt(src.seen_total, 10) || 0),
            category_name: String(src.category_name || ''),
            updated_at: Number(src.updated_at || nowTs())
        };
    }

    function getWordProgress(wordId) {
        const id = toInt(wordId);
        if (!id) return normalizeWordProgress({});
        const store = loadStore();
        const key = String(id);
        if (!store.words[key]) {
            store.words[key] = normalizeWordProgress({});
        } else {
            store.words[key] = normalizeWordProgress(store.words[key]);
        }
        return store.words[key];
    }

    function setWordProgress(wordId, nextEntry) {
        const id = toInt(wordId);
        if (!id) return normalizeWordProgress({});
        const store = loadStore();
        const key = String(id);
        store.words[key] = normalizeWordProgress(nextEntry);
        store.words[key].updated_at = nowTs();
        saveStore();
        return store.words[key];
    }

    function getCategoryNameForWord(word) {
        if (!word) return '';
        if (word.__categoryName) return String(word.__categoryName);
        const all = Array.isArray(word.all_categories) ? word.all_categories : [];
        if (all.length) return String(all[0] || '');
        return String(State.currentCategoryName || '');
    }

    function stripGenderVariation(value) {
        return (value === null || value === undefined)
            ? ''
            : String(value).replace(/[\uFE0E\uFE0F]/g, '');
    }

    function getGenderOptions() {
        const data = root.llToolsFlashcardsData || {};
        const raw = Array.isArray(data.genderOptions) ? data.genderOptions : [];
        const out = [];
        const seen = {};
        raw.forEach(function (option) {
            const val = stripGenderVariation(String(option || '')).trim();
            if (!val) return;
            const key = val.toLowerCase();
            if (seen[key]) return;
            seen[key] = true;
            out.push(val);
        });
        return out;
    }

    function normalizeGenderValue(value, options) {
        const base = stripGenderVariation(String(value || '')).trim();
        if (!base) return '';
        const lowered = base.toLowerCase();
        const opts = Array.isArray(options) ? options : [];
        for (let i = 0; i < opts.length; i++) {
            const opt = stripGenderVariation(String(opts[i] || '')).trim();
            if (opt && opt.toLowerCase() === lowered) {
                return opt;
            }
        }
        if (lowered === 'masculine' || lowered === 'feminine') {
            const symbol = lowered === 'masculine' ? '♂' : '♀';
            for (let i = 0; i < opts.length; i++) {
                const opt = stripGenderVariation(String(opts[i] || '')).trim();
                if (opt === symbol) return opt;
            }
        }
        return '';
    }

    function getCategoryConfig(categoryName) {
        if (Selection && typeof Selection.getCategoryConfig === 'function') {
            return Selection.getCategoryConfig(categoryName);
        }
        return { prompt_type: 'audio', option_type: 'image' };
    }

    function getGenderAssetRequirements(categoryName) {
        const cfg = getCategoryConfig(categoryName);
        const optionType = cfg.option_type || cfg.mode || 'image';
        const promptType = cfg.prompt_type || 'audio';
        const requiresAudio = (promptType === 'audio') || optionType === 'audio' || optionType === 'text_audio';
        const requiresImage = (promptType === 'image') || optionType === 'image';
        return { requiresAudio, requiresImage };
    }

    function isEligibleWord(word, genderOptions) {
        if (!word || !genderOptions.length) return false;
        const posRaw = word.part_of_speech;
        const pos = Array.isArray(posRaw) ? posRaw : (posRaw ? [posRaw] : []);
        const isNoun = pos.some(function (entry) { return String(entry).toLowerCase() === 'noun'; });
        if (!isNoun) return false;

        const categoryName = getCategoryNameForWord(word);
        const requirements = getGenderAssetRequirements(categoryName);
        if (requirements.requiresAudio && !(word.audio || word.has_audio)) return false;
        if (requirements.requiresImage && !(word.image || word.has_image)) return false;

        const normalizedGender = normalizeGenderValue(word.grammatical_gender, genderOptions);
        if (!normalizedGender) return false;
        try { word.__gender_label = normalizedGender; } catch (_) { /* no-op */ }
        return true;
    }

    function collectEligibleWords() {
        const byCategory = State.wordsByCategory || {};
        const categoryNames = Array.isArray(State.categoryNames) ? State.categoryNames.slice() : [];
        const selected = categoryNames.length ? categoryNames : Object.keys(byCategory);
        const options = getGenderOptions();
        const out = [];
        selected.forEach(function (name) {
            const list = Array.isArray(byCategory[name]) ? byCategory[name] : [];
            list.forEach(function (word) {
                if (!word) return;
                if (!isEligibleWord(word, options)) return;
                const id = toInt(word.id);
                if (!id) return;
                try { word.__categoryName = name; } catch (_) { /* no-op */ }
                out.push(word);
            });
        });

        const dedup = {};
        const unique = [];
        out.forEach(function (word) {
            const id = toInt(word.id);
            if (!id || dedup[id]) return;
            dedup[id] = true;
            unique.push(word);
        });
        return unique;
    }

    function parsePendingPlan() {
        const data = root.llToolsFlashcardsData || {};
        const raw = data.genderSessionPlan;
        if (!raw || typeof raw !== 'object') {
            return null;
        }
        const hasExplicitLevel = Object.prototype.hasOwnProperty.call(raw, 'level');
        const level = hasExplicitLevel ? normalizeLevel(raw.level) : 0;
        const wordIds = (Array.isArray(raw.word_ids) ? raw.word_ids : [])
            .map(toInt)
            .filter(function (id) { return id > 0; });
        return {
            level: level,
            word_ids: wordIds,
            launch_source: String(raw.launch_source || data.genderLaunchSource || 'direct'),
            force_intro: !!raw.force_intro,
            reason_code: String(raw.reason_code || '')
        };
    }

    function consumePendingPlan() {
        const plan = parsePendingPlan();
        const data = root.llToolsFlashcardsData || {};
        try { delete data.genderSessionPlan; } catch (_) { /* no-op */ }
        return plan;
    }

    function sortWordsForLevel(words, level) {
        const list = Array.isArray(words) ? words.slice() : [];
        if (level === LEVEL_ONE) {
            list.sort(function (left, right) {
                const a = getWordProgress(left.id);
                const b = getWordProgress(right.id);
                if (a.seen_total !== b.seen_total) return a.seen_total - b.seen_total;
                return Math.random() - 0.5;
            });
            return list;
        }

        list.sort(function (left, right) {
            const a = getWordProgress(left.id);
            const b = getWordProgress(right.id);
            if (a.confidence !== b.confidence) return a.confidence - b.confidence;
            const aWrong = (level === LEVEL_TWO) ? a.level2_wrong : a.level3_wrong;
            const bWrong = (level === LEVEL_TWO) ? b.level2_wrong : b.level3_wrong;
            if (aWrong !== bWrong) return bWrong - aWrong;
            return Math.random() - 0.5;
        });
        return list;
    }

    function pickChunkWordIds(words, chunkSize, mixCategories) {
        const list = Array.isArray(words) ? words.slice() : [];
        const size = Math.max(1, parseInt(chunkSize, 10) || CHUNK_SIZE);
        if (!list.length) return [];

        if (!mixCategories) {
            return list.slice(0, size).map(function (word) { return toInt(word && word.id); }).filter(function (id) { return id > 0; });
        }

        const buckets = {};
        const categoryOrder = [];
        list.forEach(function (word) {
            if (!word) return;
            const categoryName = getCategoryNameForWord(word) || '';
            if (!Object.prototype.hasOwnProperty.call(buckets, categoryName)) {
                buckets[categoryName] = [];
                categoryOrder.push(categoryName);
            }
            buckets[categoryName].push(word);
        });

        if (categoryOrder.length <= 1) {
            return list.slice(0, size).map(function (word) { return toInt(word && word.id); }).filter(function (id) { return id > 0; });
        }

        const out = [];
        const seen = {};
        while (out.length < size) {
            let addedAny = false;
            for (let i = 0; i < categoryOrder.length && out.length < size; i++) {
                const bucket = buckets[categoryOrder[i]];
                if (!bucket || !bucket.length) continue;
                const nextWord = bucket.shift();
                const wordId = toInt(nextWord && nextWord.id);
                if (!wordId || seen[wordId]) continue;
                seen[wordId] = true;
                out.push(wordId);
                addedAny = true;
            }
            if (!addedAny) break;
        }

        if (out.length < size) {
            list.forEach(function (word) {
                if (out.length >= size) return;
                const wordId = toInt(word && word.id);
                if (!wordId || seen[wordId]) return;
                seen[wordId] = true;
                out.push(wordId);
            });
        }

        return out;
    }

    function inferLevelFromWords(words) {
        const list = Array.isArray(words) ? words : [];
        if (!list.length) return LEVEL_ONE;
        let minLevel = LEVEL_THREE;
        list.forEach(function (word) {
            const entry = getWordProgress(word && word.id);
            const level = normalizeLevel(entry.level);
            if (level < minLevel) minLevel = level;
        });
        return clamp(minLevel, LEVEL_ONE, LEVEL_THREE);
    }

    function buildDefaultPlan(eligibleWords) {
        const words = Array.isArray(eligibleWords) ? eligibleWords.slice() : [];
        if (!words.length) return null;

        const categoryIds = {};
        words.forEach(function (word) {
            const cat = getCategoryNameForWord(word);
            if (cat) categoryIds[cat] = true;
        });
        const categoryCount = Object.keys(categoryIds).length;

        const buckets = { 1: [], 2: [], 3: [] };
        words.forEach(function (word) {
            const entry = getWordProgress(word.id);
            const level = normalizeLevel(entry.level);
            buckets[level].push(word);
        });

        let targetLevel = LEVEL_ONE;
        if (buckets[LEVEL_ONE].length) targetLevel = LEVEL_ONE;
        else if (buckets[LEVEL_TWO].length) targetLevel = LEVEL_TWO;
        else targetLevel = LEVEL_THREE;

        const targetWords = sortWordsForLevel(buckets[targetLevel], targetLevel);
        const launchSource = String((root.llToolsFlashcardsData && root.llToolsFlashcardsData.genderLaunchSource) || 'direct');
        const shouldMixCategories = launchSource === 'dashboard' && categoryCount > 1;
        const chosenIds = pickChunkWordIds(targetWords, CHUNK_SIZE, shouldMixCategories);
        const chosen = chosenIds.map(function (wordId) { return words.find(function (word) { return toInt(word && word.id) === wordId; }); }).filter(Boolean);
        const allUnseenInSingleCategory = (
            categoryCount === 1 &&
            targetLevel === LEVEL_ONE &&
            chosen.length > 0 &&
            chosen.every(function (word) {
                const entry = getWordProgress(word.id);
                return entry.seen_total === 0 && !entry.intro_seen;
            })
        );

        return {
            level: targetLevel,
            word_ids: chosenIds,
            launch_source: launchSource,
            force_intro: allUnseenInSingleCategory,
            reason_code: 'auto_level_select'
        };
    }

    function hydrateSession(plan, eligibleWords) {
        const eligible = Array.isArray(eligibleWords) ? eligibleWords.slice() : [];
        const byId = {};
        eligible.forEach(function (word) {
            const id = toInt(word.id);
            if (!id) return;
            byId[id] = word;
        });

        const requestedIds = (plan && Array.isArray(plan.word_ids) && plan.word_ids.length)
            ? plan.word_ids.map(toInt).filter(function (id) { return id > 0 && byId[id]; })
            : [];
        let level = normalizeLevel(plan && plan.level);
        if (!plan || !toInt(plan.level)) {
            const inferFromWords = requestedIds.length
                ? requestedIds.map(function (wordId) { return byId[wordId]; }).filter(Boolean)
                : eligible;
            level = inferLevelFromWords(inferFromWords);
        }

        const shouldMixCategories = String((plan && plan.launch_source) || '') === 'dashboard';
        const activeIds = requestedIds.length
            ? requestedIds
            : pickChunkWordIds(
                sortWordsForLevel(
                    eligible.filter(function (word) {
                        const entry = getWordProgress(word.id);
                        return normalizeLevel(entry.level) === level;
                    }),
                    level
                ),
                CHUNK_SIZE,
                shouldMixCategories
            );

        if (!activeIds.length) {
            return false;
        }

        session.ready = true;
        session.level = level;
        session.launchSource = String((plan && plan.launch_source) || 'direct');
        session.currentPlan = {
            level: level,
            word_ids: activeIds.slice(),
            launch_source: session.launchSource,
            force_intro: !!(plan && plan.force_intro),
            reason_code: String((plan && plan.reason_code) || '')
        };
        session.resultsActions = null;
        session.lastWordId = 0;
        session.replayQueue = [];
        session.stats = {
            correct: 0,
            wrong: 0,
            dontKnow: 0,
            beforeIntroCorrect: 0,
            afterIntroCorrect: 0
        };
        session.allEligibleWords = eligible.slice();
        session.allEligibleIds = eligible.map(function (word) { return toInt(word.id); }).filter(function (id) { return id > 0; });
        session.activeWordIds = activeIds.slice();
        session.activeWordLookup = {};
        session.wordsById = {};
        session.categoryByWordId = {};
        session.wordState = {};

        activeIds.forEach(function (wordId) {
            const word = byId[wordId];
            if (!word) return;
            session.activeWordLookup[wordId] = true;
            session.wordsById[wordId] = word;
            session.categoryByWordId[wordId] = getCategoryNameForWord(word);
            session.wordState[wordId] = {
                requiredStreak: 1,
                correctStreak: 0,
                passed: false,
                answers: 0,
                wrong: 0
            };
        });

        const introNeededByWord = activeIds.some(function (wordId) {
            const entry = getWordProgress(wordId);
            return !entry.intro_seen;
        });
        session.introPending = (level === LEVEL_ONE) && (introNeededByWord || !!(plan && plan.force_intro));
        session.introComplete = !session.introPending;
        session.introWords = activeIds
            .map(function (wordId) { return session.wordsById[wordId]; })
            .filter(Boolean);
        return true;
    }

    function ensureSessionReady() {
        if (session.ready) return true;
        const eligible = collectEligibleWords();
        if (!eligible.length) {
            return false;
        }
        const pending = session.pendingPlan || consumePendingPlan();
        const plan = pending || buildDefaultPlan(eligible);
        if (!plan) return false;
        return hydrateSession(plan, eligible);
    }

    function resetRoundSequence() {
        round.sequenceToken += 1;
        round.targetWordId = 0;
        round.introStartedAt = 0;
        round.introEndedAt = 0;
        round.answerAt = 0;
        round.answerLocked = false;

        if (round.timers && round.timers.length) {
            round.timers.forEach(function (id) { clearTimeout(id); });
        }
        round.timers = [];

        if (round.managedAudio && typeof round.managedAudio.stop === 'function') {
            try { round.managedAudio.stop(); } catch (_) { /* no-op */ }
        }
        if (round.managedAudio && typeof round.managedAudio.cleanup === 'function') {
            try { round.managedAudio.cleanup(); } catch (_) { /* no-op */ }
        }
        round.managedAudio = null;

        if (round.countdownEl && round.countdownEl.length) {
            round.countdownEl.remove();
        }
        round.countdownEl = null;
    }

    function scheduleRoundTimeout(fn, delay) {
        const ms = Math.max(0, parseInt(delay, 10) || 0);
        const id = setTimeout(fn, ms);
        round.timers.push(id);
        if (State && typeof State.addTimeout === 'function') {
            State.addTimeout(id);
        }
        return id;
    }

    function waitRound(delay, token) {
        return new Promise(function (resolve) {
            scheduleRoundTimeout(function () {
                if (token !== round.sequenceToken) {
                    resolve(false);
                    return;
                }
                resolve(true);
            }, delay);
        });
    }

    function cleanupManagedAudio(managed) {
        if (!managed) return;
        if (typeof managed.stop === 'function') {
            try { managed.stop(); } catch (_) { /* no-op */ }
        }
        if (typeof managed.cleanup === 'function') {
            try { managed.cleanup(); } catch (_) { /* no-op */ }
        }
    }

    function playManagedAudio(url, token, options) {
        if (!url) return Promise.resolve(false);
        if (!FlashcardAudio || typeof FlashcardAudio.createIntroductionAudio !== 'function') {
            return Promise.resolve(false);
        }
        if (token !== round.sequenceToken) {
            return Promise.resolve(false);
        }

        const opts = (options && typeof options === 'object') ? options : {};
        const managed = FlashcardAudio.createIntroductionAudio(url);
        if (!managed || typeof managed.playUntilEnd !== 'function') {
            cleanupManagedAudio(managed);
            return Promise.resolve(false);
        }
        round.managedAudio = managed;
        if (typeof opts.onStart === 'function') {
            try { opts.onStart(); } catch (_) { /* no-op */ }
        }

        if (Dom && typeof Dom.bindRepeatButtonAudio === 'function') {
            try {
                Dom.bindRepeatButtonAudio(managed.audio || null);
                Dom.setRepeatButton && Dom.setRepeatButton('stop');
            } catch (_) { /* no-op */ }
        }

        let watchdogId = null;
        const watchdog = new Promise(function (resolve) {
            watchdogId = setTimeout(function () { resolve('watchdog'); }, INTRO_WATCHDOG_MS);
        });

        return Promise.race([managed.playUntilEnd().then(function () { return 'played'; }), watchdog])
            .then(function () {
                if (watchdogId) clearTimeout(watchdogId);
                if (round.managedAudio === managed) {
                    round.managedAudio = null;
                }
                cleanupManagedAudio(managed);
                if (Dom && typeof Dom.setRepeatButton === 'function') {
                    Dom.setRepeatButton('play');
                }
                return token === round.sequenceToken;
            })
            .catch(function () {
                if (watchdogId) clearTimeout(watchdogId);
                if (round.managedAudio === managed) {
                    round.managedAudio = null;
                }
                cleanupManagedAudio(managed);
                return false;
            });
    }

    function startCountdown(token) {
        const $ = root.jQuery;
        if (!$ || token !== round.sequenceToken) return Promise.resolve(token === round.sequenceToken);
        const $prompt = $('#ll-tools-prompt');
        if (!$prompt.length) return Promise.resolve(token === round.sequenceToken);

        if (round.countdownEl && round.countdownEl.length) {
            round.countdownEl.remove();
        }
        const $countdown = $('<div>', {
            class: 'll-tools-listening-countdown ll-gender-level2-countdown',
            'aria-hidden': 'true'
        });
        $prompt.append($countdown);
        round.countdownEl = $countdown;

        return new Promise(function (resolve) {
            let current = 3;
            const tick = function () {
                if (token !== round.sequenceToken) {
                    resolve(false);
                    return;
                }
                if (!$countdown.length) {
                    resolve(false);
                    return;
                }
                $countdown.empty().append($('<span>', { class: 'digit', text: String(current) }));
                current -= 1;
                if (current <= 0) {
                    scheduleRoundTimeout(function () {
                        if (round.countdownEl && round.countdownEl.length) {
                            round.countdownEl.remove();
                        }
                        round.countdownEl = null;
                        resolve(token === round.sequenceToken);
                    }, Math.max(160, Math.floor(COUNTDOWN_STEP_MS * 0.25)));
                    return;
                }
                scheduleRoundTimeout(tick, COUNTDOWN_STEP_MS);
            };
            tick();
        });
    }

    function getIsolationAudio(word) {
        if (!word) return '';
        if (FlashcardAudio && typeof FlashcardAudio.selectBestAudio === 'function') {
            return FlashcardAudio.selectBestAudio(word, ['isolation', 'question', 'introduction']) || '';
        }
        return word.audio || '';
    }

    function getIntroductionAudio(word) {
        if (!word) return '';
        if (FlashcardAudio && typeof FlashcardAudio.selectBestAudio === 'function') {
            return FlashcardAudio.selectBestAudio(word, ['introduction', 'in sentence', 'isolation']) || '';
        }
        return word.audio || '';
    }

    function prepLevelTwoAudioGate() {
        if (!FlashcardAudio) return;
        try {
            if (typeof FlashcardAudio.pauseAllAudio === 'function') {
                FlashcardAudio.pauseAllAudio();
            }
        } catch (_) { /* no-op */ }
        try {
            if (typeof FlashcardAudio.setTargetAudioHasPlayed === 'function') {
                FlashcardAudio.setTargetAudioHasPlayed(false);
            }
        } catch (_) { /* no-op */ }
    }

    function releaseLevelTwoAudioGate() {
        if (!FlashcardAudio) return;
        try {
            if (typeof FlashcardAudio.setTargetAudioHasPlayed === 'function') {
                FlashcardAudio.setTargetAudioHasPlayed(true);
            }
        } catch (_) { /* no-op */ }
    }

    async function runLevelTwoSequence(word) {
        const token = round.sequenceToken;
        const isolationUrl = getIsolationAudio(word);
        const introUrl = getIntroductionAudio(word) || isolationUrl;

        if (!isolationUrl && !introUrl) {
            return;
        }

        prepLevelTwoAudioGate();

        if (isolationUrl) {
            const isolationPlayed = await playManagedAudio(isolationUrl, token);
            if (!isolationPlayed || token !== round.sequenceToken || round.answerLocked) {
                releaseLevelTwoAudioGate();
                return;
            }
            releaseLevelTwoAudioGate();
            const keepGoing = await waitRound(INTRO_GAP_MS, token);
            if (!keepGoing || round.answerLocked) return;
        } else {
            releaseLevelTwoAudioGate();
        }

        const countdownDone = await startCountdown(token);
        if (!countdownDone || token !== round.sequenceToken || round.answerLocked) {
            return;
        }

        if (!introUrl) {
            return;
        }

        round.introStartedAt = nowTs();
        await playManagedAudio(introUrl, token, {
            onStart: function () {
                round.introStartedAt = nowTs();
            }
        });
        if (token === round.sequenceToken) {
            round.introEndedAt = nowTs();
        }
    }

    function showGenderIntroCard(word, index) {
        const $ = root.jQuery;
        if (!$ || !word) return null;
        const categoryName = getCategoryNameForWord(word);
        const genderLabel = word.__gender_label || normalizeGenderValue(word.grammatical_gender, getGenderOptions()) || '';
        const displayGender = String(genderLabel || '').trim();
        const markerText = displayGender || '?';

        const $card = $('<div>', {
            class: 'flashcard-container ll-gender-intro-card flashcard-size-' + (root.llToolsFlashcardsData && root.llToolsFlashcardsData.imageSize || 'small'),
            'data-gender-intro-index': index,
            'data-word-id': toInt(word.id)
        });
        const $badge = $('<span>', {
            class: 'll-gender-intro-badge',
            text: markerText
        });
        $card.append($badge);

        if (word.image) {
            $('<img>', {
                src: word.image,
                alt: '',
                'aria-hidden': 'true',
                class: 'quiz-image'
            }).appendTo($card);
        } else {
            $('<div>', {
                class: 'quiz-text',
                text: word.label || word.title || ''
            }).appendTo($card);
        }

        if (categoryName) {
            $('<span>', {
                class: 'll-gender-intro-category',
                text: categoryName
            }).appendTo($card);
        }

        return $card;
    }

    async function runIntroductionSequence(words, context) {
        const $ = root.jQuery;
        if (!$ || !Array.isArray(words) || !words.length) {
            session.introComplete = true;
            session.introPending = false;
            return;
        }

        const token = round.sequenceToken;
        const $container = $('#ll-tools-flashcard');
        const $content = $('#ll-tools-flashcard-content');
        $container.removeClass('audio-line-layout').empty();
        $content.removeClass('audio-line-mode');

        words.forEach(function (word, index) {
            const $card = showGenderIntroCard(word, index);
            if ($card) {
                $card.css('display', 'none');
                $container.append($card);
            }
        });

        $('.ll-gender-intro-card').fadeIn(260);
        Dom.hideLoading && Dom.hideLoading();
        Dom.disableRepeatButton && Dom.disableRepeatButton();

        for (let idx = 0; idx < words.length; idx++) {
            if (token !== round.sequenceToken || State.abortAllOperations) break;

            const word = words[idx];
            const $active = $('.ll-gender-intro-card[data-gender-intro-index="' + idx + '"]');
            $('.ll-gender-intro-card').removeClass('ll-gender-intro-card--active');
            $active.addClass('ll-gender-intro-card--active');

            const introUrl = getIntroductionAudio(word);
            const isoUrl = getIsolationAudio(word);
            const firstClip = introUrl || isoUrl;
            const secondClip = isoUrl && introUrl && isoUrl !== introUrl ? isoUrl : firstClip;

            if (firstClip) {
                await playManagedAudio(firstClip, token);
            }
            if (token !== round.sequenceToken) break;
            await waitRound(INTRO_GAP_MS, token);
            if (token !== round.sequenceToken) break;
            if (secondClip) {
                await playManagedAudio(secondClip, token);
            }
            if (token !== round.sequenceToken) break;
            await waitRound(INTRO_WORD_GAP_MS, token);

            const progress = getWordProgress(word.id);
            progress.intro_seen = true;
            progress.category_name = getCategoryNameForWord(word);
            progress.seen_total = Math.max(0, progress.seen_total) + 1;
            setWordProgress(word.id, progress);
        }

        session.introComplete = true;
        session.introPending = false;

        $('.ll-gender-intro-card').addClass('fade-out');
        scheduleRoundTimeout(function () {
            if (token !== round.sequenceToken || State.abortAllOperations) return;
            if (context && typeof context.startQuizRound === 'function') {
                State.transitionTo(STATES.QUIZ_READY, 'Gender intro complete');
                context.startQuizRound();
            }
        }, 320);
    }

    function getWordState(wordId) {
        const id = toInt(wordId);
        if (!id || !session.wordState[id]) {
            return {
                requiredStreak: 1,
                correctStreak: 0,
                passed: false,
                answers: 0,
                wrong: 0
            };
        }
        return session.wordState[id];
    }

    function setWordState(wordId, nextState) {
        const id = toInt(wordId);
        if (!id) return;
        session.wordState[id] = {
            requiredStreak: Math.max(1, parseInt(nextState.requiredStreak, 10) || 1),
            correctStreak: Math.max(0, parseInt(nextState.correctStreak, 10) || 0),
            passed: !!nextState.passed,
            answers: Math.max(0, parseInt(nextState.answers, 10) || 0),
            wrong: Math.max(0, parseInt(nextState.wrong, 10) || 0)
        };
    }

    function queueReplay(wordId) {
        const id = toInt(wordId);
        if (!id) return;
        if (session.replayQueue.indexOf(id) !== -1) return;
        session.replayQueue.push(id);
    }

    function dequeueReplayAvoidingLast() {
        if (!Array.isArray(session.replayQueue) || !session.replayQueue.length) {
            return 0;
        }
        let idx = session.replayQueue.findIndex(function (wordId) { return wordId !== session.lastWordId; });
        if (idx < 0) idx = 0;
        const picked = toInt(session.replayQueue[idx]);
        session.replayQueue.splice(idx, 1);
        return picked;
    }

    function isWordPassed(wordId) {
        const state = getWordState(wordId);
        return !!state.passed;
    }

    function getUnpassedWordIds() {
        return session.activeWordIds.filter(function (wordId) {
            return !isWordPassed(wordId);
        });
    }

    function allWordsPassed() {
        return session.activeWordIds.length > 0 && session.activeWordIds.every(function (wordId) {
            return isWordPassed(wordId);
        });
    }

    function pickNextWordId() {
        const fromReplay = dequeueReplayAvoidingLast();
        if (fromReplay && session.wordsById[fromReplay]) {
            return fromReplay;
        }

        const pool = getUnpassedWordIds().filter(function (wordId) {
            return wordId !== session.lastWordId;
        });
        if (pool.length) {
            return pool[Math.floor(Math.random() * pool.length)];
        }

        const fallback = getUnpassedWordIds();
        if (fallback.length) {
            return fallback[0];
        }
        return 0;
    }

    function updateCategoryForWord(wordId) {
        const categoryName = session.categoryByWordId[wordId] || '';
        const list = State.wordsByCategory && categoryName ? (State.wordsByCategory[categoryName] || []) : [];
        if (categoryName) {
            State.currentCategoryName = categoryName;
            State.currentCategory = list;
            State.categoryRoundCount[categoryName] = (State.categoryRoundCount[categoryName] || 0) + 1;
            State.currentCategoryRoundCount = (State.currentCategoryRoundCount || 0) + 1;
            try {
                if (Dom && typeof Dom.updateCategoryNameDisplay === 'function') {
                    Dom.updateCategoryNameDisplay(categoryName);
                }
            } catch (_) { /* no-op */ }
        }
    }

    function computeAnswerTiming() {
        if (session.level !== LEVEL_TWO) return 'na';
        if (!round.answerAt) return 'na';
        if (!round.introStartedAt) return 'before_intro';
        return round.answerAt < round.introStartedAt ? 'before_intro' : 'after_intro';
    }

    function applyAnswerToWordState(wordId, isCorrect) {
        const state = getWordState(wordId);
        state.answers += 1;
        if (isCorrect) {
            state.correctStreak += 1;
            if (state.correctStreak >= state.requiredStreak) {
                state.passed = true;
                state.requiredStreak = 1;
            } else {
                state.passed = false;
                queueReplay(wordId);
            }
        } else {
            state.wrong += 1;
            state.passed = false;
            state.correctStreak = 0;
            state.requiredStreak = 2;
            queueReplay(wordId);
        }
        setWordState(wordId, state);
        return state;
    }

    function updateWordProgressFromAnswer(word, answerMeta) {
        const id = toInt(word && word.id);
        if (!id) return normalizeWordProgress({});
        const entry = getWordProgress(id);
        const isCorrect = !!answerMeta.isCorrect;
        const isDontKnow = !!answerMeta.isDontKnow;
        const timing = String(answerMeta.timing || 'na');
        const wordState = answerMeta.wordState || getWordState(id);

        entry.seen_total = Math.max(0, entry.seen_total) + 1;
        entry.category_name = getCategoryNameForWord(word);

        if (session.level === LEVEL_ONE) {
            if (isCorrect && wordState.passed) {
                entry.level1_passes += 1;
                entry.intro_seen = true;
                entry.confidence = Math.max(entry.confidence, 0);
                if (entry.level < LEVEL_TWO) {
                    entry.level = LEVEL_TWO;
                }
            } else if (!isCorrect) {
                entry.level1_failures += 1;
            }
        } else if (session.level === LEVEL_TWO) {
            if (isCorrect) {
                entry.level2_correct += 1;
                if (timing === 'before_intro') {
                    entry.confidence = clamp(entry.confidence + 2, -8, 12);
                    entry.quick_correct_streak += 1;
                } else {
                    entry.confidence = clamp(entry.confidence + 1, -8, 12);
                    entry.quick_correct_streak = 0;
                }
            } else {
                entry.level2_wrong += 1;
                entry.confidence = clamp(entry.confidence - 2, -8, 12);
                entry.quick_correct_streak = 0;
            }

            if (entry.confidence >= 6 && entry.quick_correct_streak >= 2) {
                entry.level = LEVEL_THREE;
                entry.confidence = Math.max(entry.confidence, 6);
            } else if (entry.confidence <= -4) {
                entry.level = LEVEL_ONE;
                entry.confidence = -2;
            } else {
                entry.level = clamp(entry.level, LEVEL_ONE, LEVEL_TWO);
            }
        } else if (session.level === LEVEL_THREE) {
            if (isCorrect) {
                entry.level3_correct += 1;
                entry.confidence = clamp(entry.confidence + 1, -8, 12);
                entry.level = LEVEL_THREE;
            } else {
                entry.level3_wrong += 1;
                entry.confidence = clamp(entry.confidence - 3, -8, 12);
                if (entry.confidence < 2) {
                    entry.level = LEVEL_TWO;
                    entry.confidence = 2;
                }
            }
        }

        if (isDontKnow) {
            entry.dont_know_count += 1;
        }

        return setWordProgress(id, entry);
    }

    function startAnswerConfetti(type, $card) {
        if (!type || !Effects || typeof Effects.startConfetti !== 'function') return;
        const rect = ($card && $card.length && $card[0] && typeof $card[0].getBoundingClientRect === 'function')
            ? $card[0].getBoundingClientRect()
            : null;
        const origin = rect ? {
            x: (rect.left + rect.width / 2) / Math.max(1, root.innerWidth || 1),
            y: (rect.top + rect.height / 2) / Math.max(1, root.innerHeight || 1)
        } : { x: 0.5, y: 0.45 };
        if (type === 'big') {
            Effects.startConfetti({
                particleCount: 80,
                spread: 80,
                angle: 90,
                origin: origin,
                duration: 450
            });
            return;
        }
        Effects.startConfetti({
            particleCount: 24,
            spread: 48,
            angle: 90,
            origin: origin,
            duration: 220
        });
    }

    function highlightAnswerCards($selected, isCorrect) {
        const $ = root.jQuery;
        if (!$) return;
        const $all = $('.flashcard-container');
        $all.addClass('ll-gender-option-locked');
        if ($selected && $selected.length) {
            $selected.addClass(isCorrect ? 'correct-answer' : 'll-gender-selected-wrong');
        }
        if (!isCorrect) {
            const $correct = $all.filter('[data-ll-gender-correct="1"]').first();
            $correct.addClass('correct-answer ll-gender-correct-reveal');
        }
    }

    async function playIntroAfterAnswer(word) {
        const token = round.sequenceToken;
        const intro = getIntroductionAudio(word) || getIsolationAudio(word);
        if (!intro) {
            return;
        }
        await waitRound(INTRO_REPLAY_GAP_MS, token);
        await playManagedAudio(intro, token, {
            onStart: function () {
                round.introStartedAt = round.introStartedAt || nowTs();
            }
        });
        round.introEndedAt = nowTs();
    }

    function buildResultsActions() {
        const msgs = getMessages();
        const level = normalizeLevel(session.level);
        const entries = session.activeWordIds.map(function (wordId) {
            return getWordProgress(wordId);
        });

        const wrongTotal = session.stats.wrong;
        const answerTotal = Math.max(1, session.stats.correct + session.stats.wrong);
        const failureRate = wrongTotal / answerTotal;

        const defaultPrimary = {
            key: 'primary',
            label: msgs.genderNextRepeat || 'Repeat This Level',
            plan: {
                level: level,
                word_ids: session.activeWordIds.slice(),
                launch_source: session.launchSource,
                reason_code: 'repeat_level'
            }
        };

        let title = msgs.genderResultsTitle || 'Gender Round Complete';
        let message = msgs.genderResultsMessage || '';
        let primary = defaultPrimary;

        if (level === LEVEL_ONE) {
            const canAdvance = entries.every(function (entry) { return normalizeLevel(entry.level) >= LEVEL_TWO; });
            if (canAdvance) {
                primary = {
                    key: 'primary',
                    label: msgs.genderNextLevel2 || 'Start Level 2',
                    plan: {
                        level: LEVEL_TWO,
                        word_ids: session.activeWordIds.slice(),
                        launch_source: session.launchSource,
                        reason_code: 'advance_to_level2'
                    }
                };
                title = msgs.genderLevelOneDoneTitle || 'Level 1 Complete';
                message = msgs.genderLevelOneDoneMessage || 'Move on to easier practice with the same words.';
            } else {
                title = msgs.genderLevelOneRetryTitle || 'Level 1: Keep Building';
                message = msgs.genderLevelOneRetryMessage || 'Repeat this level to reinforce word-gender memory.';
            }
        } else if (level === LEVEL_TWO) {
            const readyForLevelThree = entries.every(function (entry) { return normalizeLevel(entry.level) >= LEVEL_THREE; });
            if (readyForLevelThree) {
                primary = {
                    key: 'primary',
                    label: msgs.genderNextLevel3 || 'Start Level 3',
                    plan: {
                        level: LEVEL_THREE,
                        word_ids: session.activeWordIds.slice(),
                        launch_source: session.launchSource,
                        reason_code: 'advance_to_level3'
                    }
                };
                title = msgs.genderLevelTwoDoneTitle || 'Level 2 Complete';
                message = msgs.genderLevelTwoDoneMessage || 'You are ready for pure isolation practice.';
            } else if (failureRate >= 0.5) {
                primary = {
                    key: 'primary',
                    label: msgs.genderFallbackLevel1 || 'Go Back to Level 1',
                    plan: {
                        level: LEVEL_ONE,
                        word_ids: session.activeWordIds.slice(),
                        launch_source: session.launchSource,
                        reason_code: 'fallback_to_level1'
                    }
                };
                title = msgs.genderLevelTwoFallbackTitle || 'Level 2: Reset Needed';
                message = msgs.genderLevelTwoFallbackMessage || 'A quick review cycle in Level 1 should help.';
            } else {
                title = msgs.genderLevelTwoRetryTitle || 'Level 2: Keep Practicing';
                message = msgs.genderLevelTwoRetryMessage || 'Repeat this level until answers come before the intro cue.';
            }
        } else {
            const droppedWords = session.activeWordIds.filter(function (wordId) {
                const entry = getWordProgress(wordId);
                return normalizeLevel(entry.level) < LEVEL_THREE;
            });
            if (droppedWords.length) {
                primary = {
                    key: 'primary',
                    label: msgs.genderFallbackLevel2 || 'Back to Level 2',
                    plan: {
                        level: LEVEL_TWO,
                        word_ids: droppedWords.slice(0, CHUNK_SIZE),
                        launch_source: session.launchSource,
                        reason_code: 'fallback_to_level2'
                    }
                };
                title = msgs.genderLevelThreeFallbackTitle || 'Level 3: Rebuild Confidence';
                message = msgs.genderLevelThreeFallbackMessage || 'Some words need context support again.';
            } else {
                title = msgs.genderLevelThreeKeepTitle || 'Level 3 Stable';
                message = msgs.genderLevelThreeKeepMessage || 'Keep practicing isolation-only to maintain speed.';
            }
        }

        let secondary = null;
        if (session.launchSource === 'dashboard') {
            const targetLevel = normalizeLevel(primary.plan.level || level);
            const currentLookup = {};
            session.activeWordIds.forEach(function (id) { currentLookup[id] = true; });

            const extraCandidates = sortWordsForLevel(
                session.allEligibleWords.filter(function (word) {
                    const id = toInt(word.id);
                    if (!id || currentLookup[id]) return false;
                    const entry = getWordProgress(id);
                    return normalizeLevel(entry.level) === targetLevel;
                }),
                targetLevel
            );
            const extraWordIds = pickChunkWordIds(extraCandidates, CHUNK_SIZE, true);

            if (extraWordIds.length) {
                secondary = {
                    key: 'secondary',
                    label: msgs.genderNextChunk || 'Next Chunk',
                    plan: {
                        level: targetLevel,
                        word_ids: extraWordIds,
                        launch_source: session.launchSource,
                        reason_code: 'next_chunk'
                    }
                };
            }
        }

        session.resultsActions = {
            title: title,
            message: message,
            primary: primary,
            secondary: secondary
        };
        return session.resultsActions;
    }

    function getResultsActions() {
        if (session.resultsActions) return session.resultsActions;
        return buildResultsActions();
    }

    function queueResultsAction(which) {
        const key = (which === 'secondary') ? 'secondary' : 'primary';
        const actions = getResultsActions();
        const target = actions && actions[key] ? actions[key] : null;
        if (!target || !target.plan) return false;
        const plannedWordIds = (Array.isArray(target.plan.word_ids) ? target.plan.word_ids : [])
            .map(toInt)
            .filter(function (id) { return id > 0; });
        if (!plannedWordIds.length) return false;
        const data = root.llToolsFlashcardsData || {};
        data.genderSessionPlan = {
            level: normalizeLevel(target.plan.level),
            word_ids: plannedWordIds,
            launch_source: String(target.plan.launch_source || session.launchSource || 'direct'),
            reason_code: String(target.plan.reason_code || '')
        };
        data.genderLaunchSource = String(target.plan.launch_source || session.launchSource || 'direct');
        root.llToolsFlashcardsData = data;
        return true;
    }

    function initialize() {
        State.isLearningMode = false;
        State.isListeningMode = false;
        State.isGenderMode = true;
        State.isSelfCheckMode = false;
        State.completedCategories = State.completedCategories || {};
        resetRoundSequence();
        session = createEmptySession();
        session.pendingPlan = consumePendingPlan();
        return true;
    }

    function onCorrectAnswer() {
        return true;
    }

    function onWrongAnswer() {
        return true;
    }

    function onFirstRoundStart() { return true; }

    function selectTargetWord() {
        if (!ensureSessionReady()) {
            return null;
        }

        if (session.level === LEVEL_ONE && session.introPending && !session.introComplete) {
            return session.introWords.slice();
        }

        const wordId = pickNextWordId();
        if (!wordId) {
            return null;
        }

        session.lastWordId = wordId;
        updateCategoryForWord(wordId);
        round.targetWordId = wordId;
        round.answerLocked = false;
        round.answerAt = 0;
        round.introStartedAt = 0;
        round.introEndedAt = 0;

        const word = session.wordsById[wordId] || null;
        if (word) {
            try { word.__categoryName = session.categoryByWordId[wordId] || getCategoryNameForWord(word); } catch (_) { /* no-op */ }
        }
        return word;
    }

    function handleNoTarget(ctx) {
        if (State.isFirstRound && !ensureSessionReady()) {
            if (ctx && typeof ctx.showLoadingError === 'function') {
                ctx.showLoadingError();
            }
            return true;
        }

        buildResultsActions();
        State.transitionTo && State.transitionTo(STATES.SHOWING_RESULTS, 'Quiz complete');
        Results.showResults && Results.showResults();
        return true;
    }

    function beforeOptionsFill() {
        if (Array.isArray(State.wrongIndexes)) {
            State.wrongIndexes.length = 0;
        }
        return true;
    }

    function configureTargetAudio(target) {
        if (!target) return true;
        if (!FlashcardAudio || typeof FlashcardAudio.selectBestAudio !== 'function') return true;
        const preferredOrder = ['isolation', 'question', 'introduction'];
        const best = FlashcardAudio.selectBestAudio(target, preferredOrder);
        if (best) {
            target.audio = best;
        }
        return true;
    }

    function handlePostSelection(selection, context) {
        if (!Array.isArray(selection)) return false;
        if (!selection.length) return false;
        State.transitionTo(STATES.INTRODUCING_WORDS, 'Gender level-one intro');
        runIntroductionSequence(selection, context);
        return true;
    }

    function afterQuestionShown(context) {
        if (session.level !== LEVEL_TWO) {
            return false;
        }
        if (!context || !context.targetWord) {
            return false;
        }
        round.targetWordId = toInt(context.targetWord.id);
        round.answerAt = 0;
        round.introStartedAt = 0;
        round.introEndedAt = 0;
        round.answerLocked = false;
        runLevelTwoSequence(context.targetWord).catch(function () {
            // Swallow sequence failures so round can continue.
        });
        return true;
    }

    async function handleAnswer(ctx) {
        const context = (ctx && typeof ctx === 'object') ? ctx : {};
        const targetWord = context.targetWord || null;
        const wordId = toInt(targetWord && targetWord.id);
        if (!wordId || !session.wordsById[wordId]) {
            return { ignored: true };
        }
        if (round.answerLocked) {
            return { ignored: true };
        }

        round.answerLocked = true;
        round.answerAt = nowTs();

        const isCorrect = !!context.isCorrect;
        const isDontKnow = !!context.isDontKnow;
        const timing = computeAnswerTiming();

        highlightAnswerCards(context.$card, isCorrect);
        if (isCorrect) {
            if (session.level === LEVEL_TWO && timing === 'before_intro') {
                startAnswerConfetti('big', context.$card);
                session.stats.beforeIntroCorrect += 1;
            } else if (session.level === LEVEL_TWO && timing === 'after_intro') {
                startAnswerConfetti('small', context.$card);
                session.stats.afterIntroCorrect += 1;
            } else if (session.level === LEVEL_THREE) {
                startAnswerConfetti('small', context.$card);
            }
            session.stats.correct += 1;
        } else {
            session.stats.wrong += 1;
            if (isDontKnow) {
                session.stats.dontKnow += 1;
            }
        }

        const wordState = applyAnswerToWordState(wordId, isCorrect);
        const updatedProgress = updateWordProgressFromAnswer(targetWord, {
            isCorrect: isCorrect,
            isDontKnow: isDontKnow,
            timing: timing,
            wordState: wordState
        });

        // Stop any pending L2 sequence and always replay intro before advancing.
        resetRoundSequence();
        await playIntroAfterAnswer(targetWord);

        if (allWordsPassed()) {
            buildResultsActions();
        }

        return {
            ignored: false,
            isCorrect: isCorrect,
            hadWrongThisTurn: !isCorrect,
            progressPayload: {
                gender: {
                    level: normalizeLevel(updatedProgress.level),
                    confidence: clamp(updatedProgress.confidence, -8, 12),
                    quick_correct_streak: Math.max(0, parseInt(updatedProgress.quick_correct_streak, 10) || 0),
                    seen_total: Math.max(0, parseInt(updatedProgress.seen_total, 10) || 0)
                },
                gender_answer_timing: timing,
                gender_dont_know: isDontKnow
            },
            completed: allWordsPassed()
        };
    }

    root.LLFlashcards.Modes.Gender = {
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
        handlePostSelection,
        afterQuestionShown,
        handleAnswer,
        getResultsActions,
        queueResultsAction
    };
})(window);
