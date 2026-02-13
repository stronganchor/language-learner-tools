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
    const GENDER_TAP_GUARD_MS = 170;
    const WORD_REPEAT_DEMOTION_THRESHOLD = 2;
    const CATEGORY_DEMOTION_RATIO = 0.45;
    const CATEGORY_DEMOTION_MIN_CHALLENGED = 2;

    const LEVEL_ONE = 1;
    const LEVEL_TWO = 2;
    const LEVEL_THREE = 3;
    const LEVEL_ONE_MIN_CORRECT = 3;

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
            introducedWordIds: [],
            levelOneReviewPending: {},
            levelOneSingleIntroWordIds: {},
            delayNextLevelOneIntro: false,
            categorySessionStats: {},
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
            questionShownAt: 0,
            answerTapBlockUntil: 0,
            countdownEl: null,
            countdownSlot: null,
            hiddenCountdownAnchor: null
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
        if (Selection && typeof Selection.normalizeGenderValue === 'function') {
            try {
                const normalized = Selection.normalizeGenderValue(value, options);
                if (normalized) return normalized;
            } catch (_) { /* no-op */ }
        }

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
        if (lowered === 'masculine' || lowered === 'feminine' || lowered === 'male' || lowered === 'female' || lowered === 'm' || lowered === 'f') {
            const desiredRole = (lowered[0] === 'm') ? 'masculine' : 'feminine';
            for (let i = 0; i < opts.length; i++) {
                const opt = stripGenderVariation(String(opts[i] || '')).trim();
                if (!opt) continue;
                const key = opt.toLowerCase();
                const isMasculine = (key === 'masculine' || key === 'masc' || key === 'male' || key === 'm' || key === '♂');
                const isFeminine = (key === 'feminine' || key === 'fem' || key === 'female' || key === 'f' || key === '♀');
                if ((desiredRole === 'masculine' && isMasculine) || (desiredRole === 'feminine' && isFeminine)) {
                    return opt;
                }
            }
        }
        return '';
    }

    function formatGenderDisplayLabel(value) {
        const cleaned = stripGenderVariation(value).trim();
        if (cleaned === '♂' || cleaned === '♀') {
            return cleaned + '\uFE0E';
        }
        return cleaned || String(value || '');
    }

    function escapeHtml(raw) {
        return String(raw === null || raw === undefined ? '' : raw)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function normalizeGenderRole(role) {
        const cleaned = String(role || '').trim().toLowerCase();
        if (cleaned === 'masculine' || cleaned === 'feminine') return cleaned;
        return 'other';
    }

    function buildFallbackGenderVisual(value) {
        const normalized = normalizeGenderValue(value, getGenderOptions()) || stripGenderVariation(String(value || '')).trim();
        const label = formatGenderDisplayLabel(normalized || value || '');
        const key = stripGenderVariation(normalized || value || '').trim().toLowerCase();
        let role = 'other';
        if (key === 'masculine' || key === 'masc' || key === 'male' || key === 'm' || key === '♂') {
            role = 'masculine';
        } else if (key === 'feminine' || key === 'fem' || key === 'female' || key === 'f' || key === '♀') {
            role = 'feminine';
        }

        const colors = {
            masculine: '#2563EB',
            feminine: '#EC4899',
            other: '#6B7280'
        };
        const color = colors[role] || colors.other;
        let style = '--ll-gender-accent:' + color + ';';
        style += '--ll-gender-bg:' + (role === 'masculine'
            ? 'rgba(37,99,235,0.14);'
            : (role === 'feminine' ? 'rgba(236,72,153,0.14);' : 'rgba(107,114,128,0.14);'));
        style += '--ll-gender-border:' + (role === 'masculine'
            ? 'rgba(37,99,235,0.38);'
            : (role === 'feminine' ? 'rgba(236,72,153,0.38);' : 'rgba(107,114,128,0.38);'));

        return {
            value: normalized,
            label: label,
            role: role,
            style: style,
            symbol: {
                type: 'text',
                value: label || '?'
            }
        };
    }

    function getGenderVisualForWord(word) {
        const raw = (word && (word.__gender_label || word.grammatical_gender)) || '';
        if (Selection && typeof Selection.getGenderVisualForOption === 'function') {
            try {
                const visual = Selection.getGenderVisualForOption(raw, -1, getGenderOptions());
                if (visual && typeof visual === 'object') {
                    return visual;
                }
            } catch (_) { /* no-op */ }
        }
        return buildFallbackGenderVisual(raw);
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
        const armed = !!(data.genderSessionPlanArmed || data.gender_session_plan_armed);
        if (!armed) {
            return null;
        }
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
        try { delete data.genderSessionPlanArmed; } catch (_) { /* no-op */ }
        try { delete data.gender_session_plan_armed; } catch (_) { /* no-op */ }
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
        session.categorySessionStats = {};
        session.introducedWordIds = [];
        session.levelOneSingleIntroWordIds = {};
        session.delayNextLevelOneIntro = false;

        activeIds.forEach(function (wordId) {
            const word = byId[wordId];
            if (!word) return;
            session.activeWordLookup[wordId] = true;
            session.wordsById[wordId] = word;
            session.categoryByWordId[wordId] = getCategoryNameForWord(word);
            session.wordState[wordId] = {
                requiredStreak: 1,
                correctStreak: 0,
                correctTotal: 0,
                passed: false,
                answers: 0,
                wrong: 0
            };
        });

        if (level === LEVEL_ONE) {
            // Level one always starts with an explicit intro sequence for
            // the first pair (or single word), then introduces one at a time.
            const startedIntro = planLevelOneIntroBatch(Math.min(2, activeIds.length));
            if (!startedIntro) {
                session.introWords = [];
                session.introPending = false;
                session.introComplete = true;
            }
        } else {
            session.introPending = false;
            session.introComplete = true;
            session.introWords = [];
            session.introducedWordIds = activeIds.slice();
        }
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
        round.questionShownAt = 0;
        round.answerTapBlockUntil = 0;

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
        if (round.countdownSlot && round.countdownSlot.length) {
            round.countdownSlot.remove();
        }
        try { root.jQuery && root.jQuery('#ll-tools-prompt').removeClass('ll-gender-countdown-anchor'); } catch (_) { /* no-op */ }
        round.countdownSlot = null;
        if (round.hiddenCountdownAnchor && round.hiddenCountdownAnchor.length) {
            round.hiddenCountdownAnchor.removeClass('ll-gender-countdown-hidden ll-gender-countdown-anchor').removeAttr('aria-hidden');
        }
        round.hiddenCountdownAnchor = null;
        setIntroCategoryLabelHidden(false);
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

    function armAnswerTapGuard() {
        round.questionShownAt = nowTs();
        round.answerTapBlockUntil = round.questionShownAt + GENDER_TAP_GUARD_MS;
    }

    function isAnswerTapGuardActive() {
        const blockUntil = Number(round.answerTapBlockUntil || 0);
        if (!blockUntil) return false;
        return nowTs() < blockUntil;
    }

    function getIntroClipPattern(word) {
        const isolation = getIsolationAudio(word);
        const intro = getIntroductionAudio(word);
        if (intro && isolation && intro !== isolation) {
            return [intro, isolation, intro];
        }
        const fallback = intro || isolation;
        return fallback ? [fallback, fallback, fallback] : [];
    }

    function getUniqueAudioUrls(urls) {
        const out = [];
        const seen = {};
        (Array.isArray(urls) ? urls : []).forEach(function (url) {
            const key = String(url || '').trim();
            if (!key || seen[key]) return;
            seen[key] = true;
            out.push(key);
        });
        return out;
    }

    function preloadAudioUrl(url, timeoutMs) {
        return new Promise(function (resolve) {
            const source = String(url || '').trim();
            if (!source) {
                resolve(false);
                return;
            }
            let settled = false;
            let timerId = null;
            const audio = root.document && typeof root.document.createElement === 'function'
                ? root.document.createElement('audio')
                : new Audio();

            const finish = function (ready) {
                if (settled) return;
                settled = true;
                if (timerId) {
                    clearTimeout(timerId);
                }
                try {
                    audio.onloadeddata = null;
                    audio.oncanplaythrough = null;
                    audio.onerror = null;
                    audio.onstalled = null;
                    audio.pause();
                    audio.removeAttribute('src');
                    audio.load();
                } catch (_) { /* no-op */ }
                resolve(!!ready);
            };

            timerId = setTimeout(function () {
                finish(false);
            }, Math.max(350, parseInt(timeoutMs, 10) || 2200));

            try {
                audio.preload = 'auto';
                audio.crossOrigin = 'anonymous';
                audio.onloadeddata = function () { finish(true); };
                audio.oncanplaythrough = function () { finish(true); };
                audio.onerror = function () { finish(false); };
                audio.onstalled = function () { finish(false); };
                audio.src = source;
                audio.load();
            } catch (_) {
                finish(false);
            }
        });
    }

    function primeIntroAudio(words, token) {
        if (token !== round.sequenceToken || State.abortAllOperations) {
            return Promise.resolve(false);
        }
        const introWords = (Array.isArray(words) ? words : []).slice(0, 2);
        if (!introWords.length) return Promise.resolve(true);

        const perWordUrls = introWords
            .map(function (word) { return getUniqueAudioUrls(getIntroClipPattern(word)); })
            .filter(function (urls) { return urls.length > 0; });
        if (!perWordUrls.length) return Promise.resolve(true);

        const firstRequiredUrl = perWordUrls[0][0];
        const secondaryUrls = [];
        perWordUrls.forEach(function (urls, wordIndex) {
            urls.forEach(function (url, urlIndex) {
                if (wordIndex === 0 && urlIndex === 0) return;
                secondaryUrls.push(url);
            });
        });

        if (secondaryUrls.length) {
            Promise.all(secondaryUrls.map(function (url) {
                return preloadAudioUrl(url, 2400);
            })).catch(function () { return null; });
        }

        return preloadAudioUrl(firstRequiredUrl, 2600).then(function () {
            return token === round.sequenceToken && !State.abortAllOperations;
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
        let $host = $();
        const $promptAudio = $('#ll-tools-prompt .ll-prompt-audio-button:visible').first();
        if ($promptAudio.length) {
            $promptAudio.addClass('ll-gender-countdown-anchor ll-gender-countdown-hidden').attr('aria-hidden', 'true');
            round.hiddenCountdownAnchor = $promptAudio;
            $host = $promptAudio;
        } else {
            const $repeat = $('#ll-tools-repeat-flashcard:visible').first();
            if ($repeat.length) {
                $repeat.addClass('ll-gender-countdown-anchor ll-gender-countdown-hidden').attr('aria-hidden', 'true');
                round.hiddenCountdownAnchor = $repeat;
                $host = $repeat;
            } else {
                const $content = $('#ll-tools-flashcard-content').first();
                if (!$content.length) return Promise.resolve(token === round.sequenceToken);
                if (round.countdownSlot && round.countdownSlot.length) {
                    round.countdownSlot.remove();
                }
                const $slot = $('<div>', {
                    class: 'll-gender-level2-countdown-floating',
                    'aria-hidden': 'true'
                });
                $content.append($slot);
                round.countdownSlot = $slot;
                $host = $slot;
            }
        }

        if (round.countdownEl && round.countdownEl.length) {
            round.countdownEl.remove();
        }
        const nearAudio = $host.is('button');
        const $countdown = $('<div>', {
            class: 'll-tools-listening-countdown ll-gender-level2-countdown' + (nearAudio ? ' ll-gender-level2-countdown--near-audio' : ''),
            'aria-hidden': 'true'
        });
        $host.append($countdown);
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
                        if (round.countdownSlot && round.countdownSlot.length) {
                            round.countdownSlot.remove();
                        }
                        round.countdownSlot = null;
                        if (round.hiddenCountdownAnchor && round.hiddenCountdownAnchor.length) {
                            round.hiddenCountdownAnchor.removeClass('ll-gender-countdown-hidden ll-gender-countdown-anchor').removeAttr('aria-hidden');
                        }
                        round.hiddenCountdownAnchor = null;
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

    function getExplicitIntroductionAudio(word) {
        if (!word) return '';
        const files = Array.isArray(word.audio_files) ? word.audio_files : [];
        if (files.length) {
            const preferredSpeaker = toInt(word.preferred_speaker_user_id);
            const introTypes = ['introduction', 'in sentence'];
            for (let i = 0; i < introTypes.length; i++) {
                const type = introTypes[i];
                if (preferredSpeaker) {
                    const sameSpeaker = files.find(function (af) {
                        return af && af.recording_type === type && toInt(af.speaker_user_id) === preferredSpeaker && af.url;
                    });
                    if (sameSpeaker) return String(sameSpeaker.url || '');
                }
                const any = files.find(function (af) {
                    return af && af.recording_type === type && af.url;
                });
                if (any) return String(any.url || '');
            }
            return '';
        }
        if (word.introduction_audio) return String(word.introduction_audio);
        if (word.introduction_audio_url) return String(word.introduction_audio_url);
        return '';
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

    function setIntroCategoryLabelHidden(hidden) {
        const $ = root.jQuery;
        if (!$) return;
        const $category = $('#ll-tools-category-display');
        if (!$category.length) return;
        const shouldHide = !!hidden;
        if (shouldHide) {
            if (!$category.attr('data-ll-gender-prev-visibility')) {
                $category.attr('data-ll-gender-prev-visibility', $category.css('visibility') || '');
            }
            $category.css('visibility', 'hidden');
            return;
        }
        const prev = $category.attr('data-ll-gender-prev-visibility');
        if (typeof prev === 'string') {
            $category.css('visibility', prev);
            $category.removeAttr('data-ll-gender-prev-visibility');
            return;
        }
        $category.css('visibility', '');
    }

    function showGenderIntroCard(word, index, options) {
        const $ = root.jQuery;
        if (!$ || !word) return null;
        const categoryName = getCategoryNameForWord(word);
        const visual = getGenderVisualForWord(word);
        const role = normalizeGenderRole(visual && visual.role);
        const markerLabel = String((visual && visual.label) || '').trim() || '?';
        const opts = (options && typeof options === 'object') ? options : {};
        const showCategoryOverlay = !!opts.showCategoryOverlay;

        const $card = $('<div>', {
            class: 'flashcard-container ll-gender-intro-card flashcard-size-' + (root.llToolsFlashcardsData && root.llToolsFlashcardsData.imageSize || 'small'),
            'data-gender-intro-index': index,
            'data-word-id': toInt(word.id)
        });
        $card.addClass('ll-gender-intro-card--' + role).attr('data-ll-gender-role', role);

        const $badge = $('<span>', {
            class: 'll-gender-intro-badge',
            'data-ll-gender-role': role,
            'aria-label': markerLabel,
            title: markerLabel
        });
        const symbolHtml = (Selection && typeof Selection.buildGenderSymbolMarkup === 'function')
            ? Selection.buildGenderSymbolMarkup(visual, markerLabel)
            : ('<span class="ll-gender-symbol" aria-hidden="true">' + escapeHtml(markerLabel) + '</span>');
        $badge.html(symbolHtml + '<span class="screen-reader-text">' + escapeHtml(markerLabel) + '</span>');

        if (Selection && typeof Selection.applyGenderStyleVariables === 'function') {
            Selection.applyGenderStyleVariables($card, visual && visual.style ? visual.style : '');
            Selection.applyGenderStyleVariables($badge, visual && visual.style ? visual.style : '');
        }

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

        if (showCategoryOverlay && categoryName) {
            $('<span>', {
                class: 'll-gender-intro-category',
                text: categoryName
            }).appendTo($card);
        }

        return $card;
    }

    function pulseActiveIntroCard($card) {
        const $ = root.jQuery;
        if (!$ || !$card || !$card.length) return;
        $card.removeClass('ll-gender-intro-card--active');
        // Force reflow so CSS animation restarts on every recording.
        try { void $card[0].offsetWidth; } catch (_) { /* no-op */ }
        $card.addClass('ll-gender-intro-card--active');
    }

    async function runIntroductionSequence(words, context) {
        const $ = root.jQuery;
        if (!$ || !Array.isArray(words) || !words.length) {
            session.introComplete = true;
            session.introPending = false;
            session.introWords = [];
            setIntroCategoryLabelHidden(false);
            return;
        }

        const token = round.sequenceToken;
        const $container = $('#ll-tools-flashcard');
        const $content = $('#ll-tools-flashcard-content');
        const categoryNames = words
            .map(function (word) { return getCategoryNameForWord(word); })
            .filter(function (name) { return !!String(name || '').trim(); });
        const uniqueCategories = Array.from(new Set(categoryNames));
        const mixedCategoryBatch = words.length > 1 && uniqueCategories.length > 1;
        const showCategoryOverlay = mixedCategoryBatch;
        const showTopCategoryForIntro = !mixedCategoryBatch;

        $container.removeClass('audio-line-layout').empty();
        $content.removeClass('audio-line-mode');
        setIntroCategoryLabelHidden(true);
        if (showTopCategoryForIntro) {
            const firstCategory = uniqueCategories[0] || '';
            if (firstCategory && Dom && typeof Dom.updateCategoryNameDisplay === 'function') {
                try { Dom.updateCategoryNameDisplay(firstCategory); } catch (_) { /* no-op */ }
            }
        }

        words.forEach(function (word, index) {
            const $card = showGenderIntroCard(word, index, {
                showCategoryOverlay: showCategoryOverlay
            });
            if ($card) {
                $card.css('display', 'none');
                $container.append($card);
            }
        });

        await primeIntroAudio(words, token);
        if (token !== round.sequenceToken || State.abortAllOperations) {
            setIntroCategoryLabelHidden(false);
            return;
        }

        $('.ll-gender-intro-card').fadeIn(260);
        Dom.hideLoading && Dom.hideLoading();
        Dom.disableRepeatButton && Dom.disableRepeatButton();

        for (let idx = 0; idx < words.length; idx++) {
            if (token !== round.sequenceToken || State.abortAllOperations) break;

            const word = words[idx];
            const $active = $('.ll-gender-intro-card[data-gender-intro-index="' + idx + '"]');
            $('.ll-gender-intro-card').removeClass('ll-gender-intro-card--active');
            $active.addClass('ll-gender-intro-card--active');
            if (showTopCategoryForIntro) {
                const currentCategory = getCategoryNameForWord(word);
                if (currentCategory && Dom && typeof Dom.updateCategoryNameDisplay === 'function') {
                    try { Dom.updateCategoryNameDisplay(currentCategory); } catch (_) { /* no-op */ }
                }
            }

            const clipPattern = getIntroClipPattern(word);

            for (let clipIndex = 0; clipIndex < clipPattern.length; clipIndex++) {
                pulseActiveIntroCard($active);
                const clipUrl = clipPattern[clipIndex];
                await playManagedAudio(clipUrl, token);
                if (token !== round.sequenceToken) break;
                if (clipIndex < clipPattern.length - 1) {
                    await waitRound(INTRO_GAP_MS, token);
                    if (token !== round.sequenceToken) break;
                }
            }
            if (token !== round.sequenceToken) break;
            await waitRound(INTRO_WORD_GAP_MS, token);

            const progress = getWordProgress(word.id);
            progress.intro_seen = true;
            progress.category_name = getCategoryNameForWord(word);
            progress.seen_total = Math.max(0, progress.seen_total) + 1;
            setWordProgress(word.id, progress);
            markWordIntroduced(word.id);
            if (session.level === LEVEL_ONE && words.length === 1) {
                session.levelOneSingleIntroWordIds[toInt(word.id)] = true;
            }
        }

        session.introComplete = true;
        session.introPending = false;
        session.introWords = [];
        if (session.level === LEVEL_ONE) {
            resetLevelOneReviewPending(getIntroducedWordIds());
        }
        setIntroCategoryLabelHidden(false);

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
                correctTotal: 0,
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
            correctTotal: Math.max(0, parseInt(nextState.correctTotal, 10) || 0),
            passed: !!nextState.passed,
            answers: Math.max(0, parseInt(nextState.answers, 10) || 0),
            wrong: Math.max(0, parseInt(nextState.wrong, 10) || 0)
        };
    }

    function isWordIntroduced(wordId) {
        const id = toInt(wordId);
        if (!id) return false;
        return Array.isArray(session.introducedWordIds) && session.introducedWordIds.indexOf(id) !== -1;
    }

    function markWordIntroduced(wordId) {
        const id = toInt(wordId);
        if (!id) return;
        if (!Array.isArray(session.introducedWordIds)) {
            session.introducedWordIds = [];
        }
        if (session.introducedWordIds.indexOf(id) === -1) {
            session.introducedWordIds.push(id);
        }
    }

    function getNotIntroducedWordIds() {
        return session.activeWordIds.filter(function (wordId) {
            return !isWordIntroduced(wordId);
        });
    }

    function getIntroducedWordIds() {
        return session.activeWordIds.filter(function (wordId) {
            return isWordIntroduced(wordId);
        });
    }

    function resetLevelOneReviewPending(wordIds) {
        const ids = (Array.isArray(wordIds) ? wordIds : getIntroducedWordIds())
            .map(toInt)
            .filter(function (id) { return id > 0 && session.activeWordLookup[id]; });
        const pending = {};
        ids.forEach(function (wordId) {
            pending[wordId] = true;
        });
        session.levelOneReviewPending = pending;
    }

    function markLevelOneReviewSatisfied(wordId) {
        const id = toInt(wordId);
        if (!id || !session.levelOneReviewPending || typeof session.levelOneReviewPending !== 'object') return;
        if (Object.prototype.hasOwnProperty.call(session.levelOneReviewPending, id)) {
            delete session.levelOneReviewPending[id];
        }
    }

    function markLevelOneReviewPending(wordId) {
        const id = toInt(wordId);
        if (!id || !session.activeWordLookup[id]) return;
        if (!session.levelOneReviewPending || typeof session.levelOneReviewPending !== 'object') {
            session.levelOneReviewPending = {};
        }
        session.levelOneReviewPending[id] = true;
    }

    function getLevelOneReviewPendingWordIds() {
        const pending = (session.levelOneReviewPending && typeof session.levelOneReviewPending === 'object')
            ? session.levelOneReviewPending
            : {};
        return getIntroducedWordIds().filter(function (wordId) {
            return !!pending[wordId];
        });
    }

    function allLevelOneReviewSatisfied() {
        if (session.level !== LEVEL_ONE) return true;
        return getLevelOneReviewPendingWordIds().length === 0;
    }

    function allIntroducedWordsPassed() {
        const introduced = getIntroducedWordIds();
        if (!introduced.length) return false;
        return introduced.every(function (wordId) {
            return isWordPassed(wordId);
        });
    }

    function queueReplay(wordId) {
        const id = toInt(wordId);
        if (!id) return;
        if (session.replayQueue.indexOf(id) !== -1) return;
        session.replayQueue.push(id);
    }

    function removeReplay(wordId) {
        const id = toInt(wordId);
        if (!id || !Array.isArray(session.replayQueue) || !session.replayQueue.length) return;
        const idx = session.replayQueue.indexOf(id);
        if (idx >= 0) {
            session.replayQueue.splice(idx, 1);
        }
    }

    function dequeueReplayAvoidingLast(allowedWordIds) {
        if (!Array.isArray(session.replayQueue) || !session.replayQueue.length) {
            return 0;
        }
        const allowedLookup = {};
        if (Array.isArray(allowedWordIds) && allowedWordIds.length) {
            allowedWordIds.forEach(function (wordId) {
                const id = toInt(wordId);
                if (id) allowedLookup[id] = true;
            });
        }
        const isAllowed = function (wordId) {
            const id = toInt(wordId);
            if (!id || !session.activeWordLookup[id]) return false;
            if (!Object.keys(allowedLookup).length) return true;
            return !!allowedLookup[id];
        };

        let idx = session.replayQueue.findIndex(function (wordId) {
            const id = toInt(wordId);
            return isAllowed(id) && id !== session.lastWordId;
        });
        if (idx < 0) {
            return 0;
        }
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

    function pickRandomWordId(wordIds) {
        const list = (Array.isArray(wordIds) ? wordIds : []).map(toInt).filter(function (id) { return id > 0; });
        if (!list.length) return 0;
        return list[Math.floor(Math.random() * list.length)];
    }

    function pickNextWordId(preferredWordIds) {
        const preferred = (Array.isArray(preferredWordIds) && preferredWordIds.length)
            ? preferredWordIds.map(toInt).filter(function (id) { return id > 0 && session.activeWordLookup[id]; })
            : session.activeWordIds.slice();

        if (!preferred.length) {
            return 0;
        }

        const fromReplay = dequeueReplayAvoidingLast(preferred);
        if (fromReplay && session.wordsById[fromReplay]) {
            return fromReplay;
        }

        const unpassed = preferred.filter(function (wordId) {
            return !isWordPassed(wordId);
        });
        const pool = unpassed.filter(function (wordId) {
            return wordId !== session.lastWordId;
        });
        if (pool.length) {
            return pool[Math.floor(Math.random() * pool.length)];
        }

        if (unpassed.length) {
            const filler = preferred.filter(function (wordId) {
                return wordId !== session.lastWordId;
            });
            if (filler.length) {
                return pickRandomWordId(filler);
            }
            return unpassed[0];
        }

        const fallback = preferred.filter(function (wordId) {
            return wordId !== session.lastWordId;
        });
        if (fallback.length) {
            return pickRandomWordId(fallback);
        }

        if (preferred.length === 1) {
            return preferred[0];
        }
        return 0;
    }

    function setIntroWordsFromIds(wordIds) {
        const ids = (Array.isArray(wordIds) ? wordIds : []).map(toInt).filter(function (id) { return id > 0; });
        const words = ids.map(function (id) { return session.wordsById[id]; }).filter(Boolean);
        session.introWords = words;
        session.introPending = words.length > 0;
        session.introComplete = !session.introPending;
        return words.length > 0;
    }

    function planLevelOneIntroBatch(maxCount) {
        const take = Math.max(1, parseInt(maxCount, 10) || 1);
        const notIntroduced = getNotIntroducedWordIds();
        if (!notIntroduced.length) {
            session.introWords = [];
            session.introPending = false;
            session.introComplete = true;
            return false;
        }
        const picked = [];
        for (let i = 0; i < notIntroduced.length && picked.length < take; i++) {
            const wordId = toInt(notIntroduced[i]);
            if (!wordId) continue;
            if (wordId === session.lastWordId && notIntroduced.length > take) continue;
            if (picked.indexOf(wordId) !== -1) continue;
            picked.push(wordId);
        }
        if (!picked.length) {
            picked.push(notIntroduced[0]);
        }
        return setIntroWordsFromIds(picked);
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
        const isLevelOne = session.level === LEVEL_ONE;
        const stillIntroducingLevelOne = isLevelOne && getNotIntroducedWordIds().length > 0;
        state.answers += 1;
        if (isCorrect) {
            state.correctStreak += 1;
            state.correctTotal += 1;
            const streakSatisfied = state.correctStreak >= state.requiredStreak;
            const minCorrectRequired = isLevelOne ? LEVEL_ONE_MIN_CORRECT : 1;

            if (streakSatisfied) {
                state.requiredStreak = 1;
                if (isLevelOne) {
                    const isSingleIntroWord = !!(
                        session.levelOneSingleIntroWordIds &&
                        session.levelOneSingleIntroWordIds[wordId]
                    );
                    markLevelOneReviewSatisfied(wordId);
                    if (stillIntroducingLevelOne) {
                        removeReplay(wordId);
                        if (isSingleIntroWord && state.correctTotal === 1) {
                            session.delayNextLevelOneIntro = true;
                            delete session.levelOneSingleIntroWordIds[wordId];
                        }
                    }
                }
            }

            state.passed = streakSatisfied && state.correctTotal >= minCorrectRequired;

            const shouldQueueForMastery =
                !state.passed &&
                (!isLevelOne || !stillIntroducingLevelOne);

            if (!streakSatisfied || shouldQueueForMastery) {
                queueReplay(wordId);
            }
        } else {
            state.wrong += 1;
            state.passed = false;
            state.correctStreak = 0;
            state.requiredStreak = 2;
            queueReplay(wordId);
            if (isLevelOne) {
                markLevelOneReviewPending(wordId);
            }
        }
        setWordState(wordId, state);
        return state;
    }

    function demoteEntryByOneLevel(entry) {
        if (!entry || typeof entry !== 'object') return false;
        const currentLevel = normalizeLevel(entry.level);
        if (currentLevel <= LEVEL_ONE) {
            entry.level = LEVEL_ONE;
            entry.confidence = clamp(Math.min(entry.confidence, -2), -8, 12);
            entry.quick_correct_streak = 0;
            return false;
        }
        if (currentLevel === LEVEL_THREE) {
            entry.level = LEVEL_TWO;
            entry.confidence = clamp(Math.min(entry.confidence, 3), -8, 12);
            entry.quick_correct_streak = 0;
            return true;
        }
        entry.level = LEVEL_ONE;
        entry.confidence = clamp(Math.min(entry.confidence, -2), -8, 12);
        entry.quick_correct_streak = 0;
        return true;
    }

    function getCategorySessionStats(categoryName) {
        const name = String(categoryName || '').trim();
        if (!name) return null;
        if (!session.categorySessionStats || typeof session.categorySessionStats !== 'object') {
            session.categorySessionStats = {};
        }
        if (!session.categorySessionStats[name]) {
            session.categorySessionStats[name] = {
                seenWordIds: {},
                challengedWordIds: {},
                demoted: false
            };
        }
        return session.categorySessionStats[name];
    }

    function markCategoryChallenge(wordId, categoryName, isCorrect, isDontKnow) {
        const id = toInt(wordId);
        if (!id) return;
        const name = String(categoryName || '').trim() || String(session.categoryByWordId[id] || '').trim();
        const stats = getCategorySessionStats(name);
        if (!stats) return;
        stats.seenWordIds[id] = true;
        if (!isCorrect || isDontKnow) {
            stats.challengedWordIds[id] = true;
        }
    }

    function maybeDemoteCategoryFromSessionPressure(categoryName) {
        const name = String(categoryName || '').trim();
        if (!name) return false;
        const stats = getCategorySessionStats(name);
        if (!stats || stats.demoted) return false;

        const categoryWordIds = session.activeWordIds.filter(function (wordId) {
            return String(session.categoryByWordId[wordId] || '').trim() === name;
        });
        if (!categoryWordIds.length) return false;

        const seenCount = Object.keys(stats.seenWordIds).length;
        const challengedCount = Object.keys(stats.challengedWordIds).length;
        if (challengedCount < CATEGORY_DEMOTION_MIN_CHALLENGED) return false;
        if (seenCount < Math.min(categoryWordIds.length, 3)) return false;
        if ((challengedCount / Math.max(1, categoryWordIds.length)) < CATEGORY_DEMOTION_RATIO) return false;

        stats.demoted = true;
        let changed = false;
        categoryWordIds.forEach(function (wordId) {
            const progress = getWordProgress(wordId);
            if (demoteEntryByOneLevel(progress)) {
                setWordProgress(wordId, progress);
                changed = true;
            }
        });
        return changed;
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

        const repeatMisses = !isCorrect && (Math.max(0, parseInt(wordState && wordState.wrong, 10) || 0) >= WORD_REPEAT_DEMOTION_THRESHOLD);
        if (repeatMisses || (isDontKnow && entry.dont_know_count >= WORD_REPEAT_DEMOTION_THRESHOLD)) {
            demoteEntryByOneLevel(entry);
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
        Effects.startConfetti({
            particleCount: 20,
            spread: 60,
            angle: 90,
            origin: origin,
            duration: 50
        });
    }

    function waitForAudioToEnd(audio, maxMs) {
        const timeoutMs = Math.max(200, parseInt(maxMs, 10) || 1200);
        return new Promise(function (resolve) {
            let finished = false;
            let timer = null;
            const done = function () {
                if (finished) return;
                finished = true;
                if (timer) {
                    clearTimeout(timer);
                    timer = null;
                }
                resolve();
            };
            timer = setTimeout(done, timeoutMs);
            if (!audio) {
                done();
                return;
            }
            try {
                if (typeof audio.addEventListener === 'function') {
                    audio.addEventListener('ended', done, { once: true });
                    audio.addEventListener('error', done, { once: true });
                    return;
                }
            } catch (_) { /* no-op */ }
            try {
                const prevEnded = audio.onended;
                audio.onended = function () {
                    try {
                        if (typeof prevEnded === 'function') prevEnded.apply(audio, arguments);
                    } catch (_) { /* no-op */ }
                    done();
                };
                const prevError = audio.onerror;
                audio.onerror = function () {
                    try {
                        if (typeof prevError === 'function') prevError.apply(audio, arguments);
                    } catch (_) { /* no-op */ }
                    done();
                };
            } catch (_) {
                done();
            }
        });
    }

    async function playAnswerFeedback(isCorrect, isDontKnow, waitForFinish) {
        if (!FlashcardAudio) return;
        if (!isCorrect && isDontKnow) return;

        if (isCorrect && typeof FlashcardAudio.playFeedback === 'function') {
            try {
                // Gender level-2 can answer before target-audio bookkeeping flips;
                // force the gate open so the standard correct ding always fires.
                if (typeof FlashcardAudio.setTargetAudioHasPlayed === 'function') {
                    FlashcardAudio.setTargetAudioHasPlayed(true);
                }
                if (waitForFinish) {
                    await new Promise(function (resolve) {
                        let settled = false;
                        const finish = function () {
                            if (settled) return;
                            settled = true;
                            resolve();
                        };
                        try { FlashcardAudio.playFeedback(true, null, finish); } catch (_) { finish(); return; }
                        setTimeout(finish, 1200);
                    });
                } else {
                    FlashcardAudio.playFeedback(true, null, null);
                }
                return;
            } catch (_) {
                // Fall through to URL-based fallback below.
            }
        }

        const url = isCorrect
            ? (typeof FlashcardAudio.getCorrectAudioURL === 'function' ? FlashcardAudio.getCorrectAudioURL() : '')
            : (typeof FlashcardAudio.getWrongAudioURL === 'function' ? FlashcardAudio.getWrongAudioURL() : '');

        if (url && typeof FlashcardAudio.createAudio === 'function' && typeof FlashcardAudio.playAudio === 'function') {
            try {
                const clip = FlashcardAudio.createAudio(url, {
                    type: isCorrect ? 'feedback-correct' : 'feedback-wrong'
                });
                if (clip) {
                    let played = FlashcardAudio.playAudio(clip);
                    if (played && typeof played.catch === 'function') {
                        played = played.catch(function () { return null; });
                    }
                    await Promise.resolve(played);
                    if (waitForFinish) {
                        await waitForAudioToEnd(clip, 1800);
                    }
                    return;
                }
            } catch (_) { /* no-op */ }
        }

        if (typeof FlashcardAudio.playFeedback === 'function') {
            if (waitForFinish) {
                await new Promise(function (resolve) {
                    let settled = false;
                    const finish = function () {
                        if (settled) return;
                        settled = true;
                        resolve();
                    };
                    try { FlashcardAudio.playFeedback(!!isCorrect, null, finish); } catch (_) { finish(); return; }
                    setTimeout(finish, 1200);
                });
                return;
            }
            try { FlashcardAudio.playFeedback(!!isCorrect, null, null); } catch (_) { /* no-op */ }
        }
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

    async function playIntroAfterAnswer(word, answerMeta) {
        const token = round.sequenceToken;
        const meta = (answerMeta && typeof answerMeta === 'object') ? answerMeta : {};
        const shouldReplay = !meta.isCorrect || !!meta.isDontKnow;
        if (!shouldReplay) {
            return;
        }
        const intro = getExplicitIntroductionAudio(word);
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

    function getResultsCategoryNames() {
        const names = [];
        const seen = {};
        session.activeWordIds.forEach(function (wordId) {
            const name = String(session.categoryByWordId[wordId] || '').trim();
            if (!name || seen[name]) return;
            seen[name] = true;
            names.push(name);
        });
        return names;
    }

    function buildDashboardSecondaryPlan(primaryLevel) {
        const fallbackLevel = normalizeLevel(primaryLevel || session.level);
        const currentLookup = {};
        session.activeWordIds.forEach(function (id) {
            const wordId = toInt(id);
            if (wordId) currentLookup[wordId] = true;
        });

        const extraWords = session.allEligibleWords.filter(function (word) {
            const id = toInt(word && word.id);
            return !!id && !currentLookup[id];
        });

        if (extraWords.length) {
            const autoPlan = buildDefaultPlan(extraWords);
            if (autoPlan && Array.isArray(autoPlan.word_ids) && autoPlan.word_ids.length) {
                const ids = autoPlan.word_ids.map(toInt).filter(function (id) { return id > 0; });
                if (ids.length) {
                    return {
                        level: normalizeLevel(autoPlan.level),
                        word_ids: ids,
                        launch_source: session.launchSource,
                        reason_code: 'next_chunk'
                    };
                }
            }

            const levelCandidates = sortWordsForLevel(extraWords, fallbackLevel);
            const ids = pickChunkWordIds(levelCandidates, CHUNK_SIZE, true);
            if (ids.length) {
                return {
                    level: fallbackLevel,
                    word_ids: ids,
                    launch_source: session.launchSource,
                    reason_code: 'next_chunk'
                };
            }
        }

        return {
            level: fallbackLevel,
            word_ids: session.activeWordIds.slice(),
            launch_source: session.launchSource,
            reason_code: 'next_chunk_repeat_current'
        };
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
                message = msgs.genderLevelOneDoneMessage || 'Advance to the next gender level with the same words.';
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
                message = msgs.genderLevelTwoRetryMessage || 'Repeat this level until you get all answers right and answer quickly.';
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
            const targetLevel = normalizeLevel(primary && primary.plan ? primary.plan.level : level);
            secondary = {
                key: 'secondary',
                label: msgs.genderNextChunk || 'Next Recommended Set',
                plan: buildDashboardSecondaryPlan(targetLevel)
            };
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
        data.genderSessionPlanArmed = true;
        data.gender_session_plan_armed = true;
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

        if (session.level === LEVEL_ONE) {
            const introduced = getIntroducedWordIds();
            const notIntroduced = getNotIntroducedWordIds();
            const latestIntroducedId = introduced.length > 2 ? toInt(introduced[introduced.length - 1]) : 0;
            const latestIntroducedState = latestIntroducedId ? getWordState(latestIntroducedId) : null;
            const latestNeedsSecondPass = !!(
                latestIntroducedState &&
                (Math.max(0, parseInt(latestIntroducedState.correctTotal, 10) || 0) < 2)
            );

            if (!introduced.length && notIntroduced.length) {
                // Safety net: if introductions were skipped, bootstrap with two words.
                if (planLevelOneIntroBatch(Math.min(2, notIntroduced.length))) {
                    return session.introWords.slice();
                }
            }

            const shouldIntroduceNext =
                introduced.length > 0 &&
                notIntroduced.length > 0 &&
                allLevelOneReviewSatisfied() &&
                !latestNeedsSecondPass &&
                !session.delayNextLevelOneIntro &&
                (!Array.isArray(session.replayQueue) || session.replayQueue.length === 0);

            if (shouldIntroduceNext && planLevelOneIntroBatch(1)) {
                return session.introWords.slice();
            }
        }

        let targetPool = null;
        if (session.level === LEVEL_ONE) {
            const introduced = getIntroducedWordIds();
            const pendingReview = getLevelOneReviewPendingWordIds();
            if (pendingReview.length > 1) {
                targetPool = pendingReview.slice();
            } else if (pendingReview.length === 1) {
                const pendingId = pendingReview[0];
                const supportWords = introduced.filter(function (wordId) {
                    return wordId !== pendingId;
                });
                targetPool = (pendingId !== session.lastWordId)
                    ? [pendingId]
                    : [pendingId].concat(supportWords);
            } else {
                targetPool = introduced;
            }
        }
        const wordId = pickNextWordId(targetPool);
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
        if (session.level === LEVEL_ONE && session.delayNextLevelOneIntro) {
            session.delayNextLevelOneIntro = false;
        }
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
        if (!context || !context.targetWord) {
            return false;
        }
        round.targetWordId = toInt(context.targetWord.id);
        round.answerAt = 0;
        round.introStartedAt = 0;
        round.introEndedAt = 0;
        round.answerLocked = false;
        armAnswerTapGuard();
        if (session.level !== LEVEL_TWO) {
            return false;
        }
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
        const categoryName = String(session.categoryByWordId[wordId] || getCategoryNameForWord(targetWord) || '').trim();

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
            } else {
                startAnswerConfetti('small', context.$card);
            }
            session.stats.correct += 1;
        } else {
            session.stats.wrong += 1;
            if (isDontKnow) {
                session.stats.dontKnow += 1;
            }
        }

        await playAnswerFeedback(isCorrect, isDontKnow, !isCorrect);

        const wordState = applyAnswerToWordState(wordId, isCorrect);
        markCategoryChallenge(wordId, categoryName, isCorrect, isDontKnow);
        let updatedProgress = updateWordProgressFromAnswer(targetWord, {
            isCorrect: isCorrect,
            isDontKnow: isDontKnow,
            timing: timing,
            wordState: wordState
        });
        if (!isCorrect || isDontKnow) {
            maybeDemoteCategoryFromSessionPressure(categoryName);
            updatedProgress = getWordProgress(wordId);
        }

        // Stop any pending L2 sequence and always replay intro before advancing.
        resetRoundSequence();
        await playIntroAfterAnswer(targetWord, {
            isCorrect: isCorrect,
            isDontKnow: isDontKnow
        });

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
        isAnswerTapGuardActive,
        handleAnswer,
        getResultsActions,
        getResultsCategoryNames,
        queueResultsAction
    };
})(window);
