(function (root) {
    'use strict';

    // Namespaces
    root.LLFlashcards = root.LLFlashcards || {};
    const State = (root.LLFlashcards.State = root.LLFlashcards.State || {});
    const Selection = (root.LLFlashcards.Selection = root.LLFlashcards.Selection || {});
    const Util = (root.LLFlashcards.Util = root.LLFlashcards.Util || {});
    const Dom = (root.LLFlashcards.Dom = root.LLFlashcards.Dom || {});
    const Cards = (root.LLFlashcards.Cards = root.LLFlashcards.Cards || {});
    const Results = (root.LLFlashcards.Results = root.LLFlashcards.Results || {});
    const FlashcardAudio = root.FlashcardAudio;
    const FlashcardLoader = root.FlashcardLoader;
    const STATES = State.STATES || {};

    function normalizeStarMode(mode) {
        const val = (mode || '').toString();
        return (val === 'only' || val === 'normal' || val === 'weighted') ? val : 'normal';
    }

    const INTRO_GAP_MS = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData.introSilenceMs === 'number')
        ? root.llToolsFlashcardsData.introSilenceMs : 800;
    const INTRO_WORD_GAP_MS = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData.introWordSilenceMs === 'number')
        ? root.llToolsFlashcardsData.introWordSilenceMs : 800;
    const INTRO_AUDIO_RETRY_LIMIT = 2;
    const LEARNING_SET_SPLIT_THRESHOLD = 15;
    const LEARNING_SET_MAX_SIZE = 12;

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

    function pickWeightedId(ids, avoidId) {
        const list = Array.isArray(ids) ? ids.slice() : [];
        if (!list.length) return null;
        const lookup = getStarredLookup();
        const mode = getStarMode();
        let pool = list;
        if (avoidId) {
            const filtered = pool.filter(id => id !== avoidId);
            if (filtered.length) pool = filtered;
        }
        if (mode === 'only') {
            pool = pool.filter(id => lookup[id]);
            if (!pool.length) return null;
        }
        if (mode === 'weighted') {
            const weighted = [];
            pool.forEach(id => {
                const w = lookup[id] ? 2 : 1;
                for (let i = 0; i < w; i++) weighted.push(id);
            });
            if (!weighted.length) return null;
            return weighted[Math.floor(Math.random() * weighted.length)];
        }
        return pool[Math.floor(Math.random() * pool.length)];
    }

    // ---- helpers (learning-specific) ----
    function ensureDefaults() {
        if (!State.wordsAnsweredSinceLastIntro) State.wordsAnsweredSinceLastIntro = new Set();
        if (!State.wordCorrectCounts) State.wordCorrectCounts = {};
        if (!Array.isArray(State.wrongAnswerQueue)) State.wrongAnswerQueue = [];
        if (typeof State.turnIndex !== 'number') State.turnIndex = 0;
        if (typeof State.learningCorrectStreak !== 'number') State.learningCorrectStreak = 0;
        if (typeof State.learningChoiceCount !== 'number') State.learningChoiceCount = 2;
        if (typeof State.MIN_CHOICE_COUNT !== 'number') State.MIN_CHOICE_COUNT = 2;
        if (typeof State.MAX_CHOICE_COUNT !== 'number') State.MAX_CHOICE_COUNT = 6;
        if (typeof State.MIN_CORRECT_COUNT !== 'number') State.MIN_CORRECT_COUNT = 3;
        if (!Array.isArray(State.introducedWordIDs)) State.introducedWordIDs = [];
        if (!State.wordIntroductionProgress) State.wordIntroductionProgress = {};
        if (!Array.isArray(State.learningWordSets)) State.learningWordSets = [];
        if (typeof State.learningWordSetIndex !== 'number') State.learningWordSetIndex = 0;
        if (typeof State.learningWordSetSignature !== 'string') State.learningWordSetSignature = '';

        // Back-compat: if wrongAnswerQueue is an array of IDs, convert to objects with randomized dueTurn
        if (State.wrongAnswerQueue.length && typeof State.wrongAnswerQueue[0] !== 'object') {
            State.wrongAnswerQueue = State.wrongAnswerQueue.map(id => ({
                id,
                dueTurn: State.turnIndex + randomDelay()
            }));
        }
    }

    function syncProgressUI() {
        if (!Dom || typeof Dom.updateLearningProgress !== 'function') return;
        Dom.updateLearningProgress(
            State.introducedWordIDs.length,
            State.totalWordCount,
            State.wordCorrectCounts,
            State.wordIntroductionProgress
        );
    }

    function getCurrentDisplayMode() {
        return (Selection && Selection.getCurrentDisplayMode)
            ? Selection.getCurrentDisplayMode()
            : 'image';
    }

    function applyConstraints(desiredCount) {
        const mode = getCurrentDisplayMode();
        let maxCount = (root.llToolsFlashcardsData && root.llToolsFlashcardsData.maxOptionsOverride)
            ? parseInt(root.llToolsFlashcardsData.maxOptionsOverride, 10)
            : 9;

        if (mode === 'text' || mode === 'text_audio') maxCount = Math.min(maxCount, 4);

        const cfg = (Selection && typeof Selection.getCategoryConfig === 'function')
            ? Selection.getCategoryConfig(State.currentCategoryName)
            : null;
        const promptType = cfg ? cfg.prompt_type : null;
        const optionType = cfg ? (cfg.option_type || cfg.mode) : mode;
        const isImagePromptAudio = (promptType === 'image') && (optionType === 'audio' || optionType === 'text_audio');
        if (isImagePromptAudio) {
            maxCount = Math.min(maxCount, 4);
        }

        const introducedCount = State.introducedWordIDs.length;
        maxCount = Math.min(maxCount, introducedCount);
        maxCount = Math.max(2, maxCount);

        return Math.min(desiredCount, maxCount);
    }

    // ----- WRONG-ANSWER SCHEDULING (randomized, like original behavior) -----
    function randomDelay() {
        return (Util && typeof Util.randomInt === 'function')
            ? Util.randomInt(1, 3)
            : (1 + Math.floor(Math.random() * 3)); // 1..3
    }

    function findWrongObj(id) {
        for (let i = 0; i < State.wrongAnswerQueue.length; i++) {
            const it = State.wrongAnswerQueue[i];
            if (it && it.id === id) return it;
        }
        return null;
    }

    function scheduleWrong(id, baseTurn) {
        const normalizedId = parseInt(id, 10) || id;
        const obj = findWrongObj(normalizedId);
        const due = (typeof baseTurn === 'number' ? baseTurn : State.turnIndex) + randomDelay();
        if (obj) {
            obj.dueTurn = due; // reschedule
        } else {
            State.wrongAnswerQueue.push({ id: normalizedId, dueTurn: due });
        }
    }

    function removeFromWrongQueue(id) {
        const normalizedId = parseInt(id, 10) || id;
        State.wrongAnswerQueue = State.wrongAnswerQueue.filter(function (x) {
            return x && x.id !== normalizedId;
        });
    }

    function readyWrongs() {
        const now = State.turnIndex;
        return State.wrongAnswerQueue.filter(x => x.dueTurn <= now);
    }

    function pickReadyWrongWithSpacing(readyList) {
        const ready = Array.isArray(readyList) ? readyList : readyWrongs();
        if (!ready.length) return { id: null, fallbackId: null };

        // Prefer not to repeat last shown
        const notRepeat = ready.filter(x => x.id !== State.lastWordShownId);
        const pool = notRepeat.length ? notRepeat : ready;

        // Random pick among ready pool
        const pick = pool[Math.floor(Math.random() * pool.length)];
        if (notRepeat.length) {
            return { id: pick ? pick.id : null, fallbackId: pick ? pick.id : null };
        }

        // If we'd repeat immediately, defer but remember a fallback in case nothing else is available
        return { id: null, fallbackId: pick ? pick.id : null };
    }

    function wordObjectById(wordId) {
        for (let name of (State.categoryNames || [])) {
            const words = (State.wordsByCategory && State.wordsByCategory[name]) || [];
            const word = words.find(w => w.id === wordId);
            if (word) {
                State.currentCategoryName = name;
                State.currentCategory = words;
                return word;
            }
        }
        return null;
    }

    function findWordObjectById(wordId) {
        const normalizedId = parseInt(wordId, 10);
        if (!normalizedId) return null;

        for (let name of (State.categoryNames || [])) {
            const words = (State.wordsByCategory && State.wordsByCategory[name]) || [];
            const word = words.find(function (item) {
                return parseInt(item && item.id, 10) === normalizedId;
            });
            if (word) {
                return word;
            }
        }
        return null;
    }

    function normalizeWordIdList(input) {
        const seen = new Set();
        const out = [];
        (Array.isArray(input) ? input : []).forEach(function (value) {
            const id = parseInt(value, 10);
            if (!id || seen.has(id)) return;
            seen.add(id);
            out.push(id);
        });
        return out;
    }

    function collectAllLearningWordIds() {
        const seen = new Set();
        const allIds = [];
        for (const name of (State.categoryNames || [])) {
            const list = (State.wordsByCategory && State.wordsByCategory[name]) || [];
            for (const w of list) {
                const id = parseInt(w && w.id, 10);
                if (!id || seen.has(id)) continue;
                seen.add(id);
                allIds.push(id);
            }
        }
        return allIds;
    }

    function buildWordIdSignature(ids) {
        return normalizeWordIdList(ids)
            .slice()
            .sort(function (a, b) { return a - b; })
            .join(',');
    }

    function buildLearningWordSets(allIds) {
        const ids = normalizeWordIdList(allIds);
        if (!ids.length) return [];
        if (ids.length <= LEARNING_SET_SPLIT_THRESHOLD) {
            return [ids];
        }

        const setCount = Math.ceil(ids.length / LEARNING_SET_MAX_SIZE);
        const baseSize = Math.floor(ids.length / setCount);
        const remainder = ids.length % setCount;
        const sets = [];
        let offset = 0;

        for (let i = 0; i < setCount; i++) {
            const size = baseSize + (i < remainder ? 1 : 0);
            sets.push(ids.slice(offset, offset + size));
            offset += size;
        }

        return sets.filter(function (set) { return Array.isArray(set) && set.length > 0; });
    }

    function getLearningWordSets() {
        return Array.isArray(State.learningWordSets) ? State.learningWordSets : [];
    }

    function getLearningWordSetIndex() {
        const sets = getLearningWordSets();
        if (!sets.length) return 0;
        const raw = parseInt(State.learningWordSetIndex, 10);
        if (!Number.isFinite(raw)) return 0;
        return Math.max(0, Math.min(raw, sets.length - 1));
    }

    function getCurrentLearningWordSetIds() {
        const sets = getLearningWordSets();
        if (!sets.length) return [];
        return normalizeWordIdList(sets[getLearningWordSetIndex()]);
    }

    function resetCurrentLearningSetProgress() {
        State.introducedWordIDs = [];
        State.wordIntroductionProgress = {};
        State.wordCorrectCounts = {};
        State.wrongAnswerQueue = [];
        State.wordsAnsweredSinceLastIntro = new Set();
        State.lastWordShownId = null;
        State.turnIndex = 0;
        State.learningCorrectStreak = 0;
        State.learningChoiceCount = State.MIN_CHOICE_COUNT || 2;
        State.currentChoiceCount = State.learningChoiceCount;
        State.isIntroducingWord = false;
    }

    function buildWeightedOrder(ids, avoidId) {
        const pool = Array.isArray(ids) ? ids.slice() : [];
        const order = [];
        let avoid = avoidId;

        while (pool.length) {
            const picked = pickWeightedId(pool, avoid);
            if (picked === null) break;
            order.push(picked);
            const idx = pool.indexOf(picked);
            if (idx > -1) {
                pool.splice(idx, 1);
            } else {
                break;
            }
            avoid = null;
        }

        return order;
    }

    function wordsConflictForOptions(leftWord, rightWord) {
        if (!leftWord || !rightWord) return false;
        if (Selection && typeof Selection.wordsConflictForOptions === 'function') {
            return Selection.wordsConflictForOptions(leftWord, rightWord);
        }
        return false;
    }

    function selectBootstrapIntroIds(notYetIntroduced, takeCount) {
        const take = parseInt(takeCount, 10);
        if (!Array.isArray(notYetIntroduced) || !notYetIntroduced.length || !take) {
            return [];
        }

        const orderedIds = buildWeightedOrder(notYetIntroduced);
        if (!orderedIds.length) return [];

        const lockedWords = (State.introducedWordIDs || [])
            .map(id => findWordObjectById(id))
            .filter(Boolean);

        if (take >= 2 && lockedWords.length === 0) {
            for (let i = 0; i < orderedIds.length; i++) {
                const firstId = orderedIds[i];
                const firstWord = findWordObjectById(firstId);
                if (!firstWord) continue;

                for (let j = i + 1; j < orderedIds.length; j++) {
                    const secondId = orderedIds[j];
                    const secondWord = findWordObjectById(secondId);
                    if (!secondWord) continue;
                    if (wordsConflictForOptions(firstWord, secondWord)) continue;
                    return [firstId, secondId];
                }
            }
        }

        const selected = [];
        const selectedWords = [];
        for (const candidateId of orderedIds) {
            if (selected.length >= take) break;
            const candidateWord = findWordObjectById(candidateId);
            if (!candidateWord) continue;

            const conflictsLocked = lockedWords.some(existing => wordsConflictForOptions(existing, candidateWord));
            const conflictsSelected = selectedWords.some(existing => wordsConflictForOptions(existing, candidateWord));
            if (conflictsLocked || conflictsSelected) continue;

            selected.push(candidateId);
            selectedWords.push(candidateWord);
        }

        if (selected.length) return selected;
        // Keep learning flow moving even when every remaining intro word conflicts.
        return [orderedIds[0]];
    }

    function pickNextIntroId(notYetIntroduced, avoidId) {
        if (!Array.isArray(notYetIntroduced) || !notYetIntroduced.length) return null;
        const orderedIds = buildWeightedOrder(notYetIntroduced, avoidId);
        if (!orderedIds.length) return null;

        const introducedWords = (State.introducedWordIDs || [])
            .map(id => findWordObjectById(id))
            .filter(Boolean);

        for (const candidateId of orderedIds) {
            const candidateWord = findWordObjectById(candidateId);
            if (!candidateWord) continue;
            const conflictsIntroduced = introducedWords.some(existing => wordsConflictForOptions(existing, candidateWord));
            if (!conflictsIntroduced) {
                return candidateId;
            }
        }

        return orderedIds[0];
    }

    // ---- initialization (called from Selection.initializeLearningMode) ----
    function initialize() {
        ensureDefaults();
        const starMode = getStarMode();
        const starredLookup = getStarredLookup();
        const allIds = collectAllLearningWordIds();
        const filteredAllIds = (starMode === 'only')
            ? allIds.filter(function (id) { return !!starredLookup[id]; })
            : allIds;
        const nextSignature = buildWordIdSignature(filteredAllIds);
        const needsRebuild =
            !getLearningWordSets().length ||
            State.learningWordSetSignature !== nextSignature;

        if (needsRebuild) {
            State.learningWordSets = buildLearningWordSets(filteredAllIds);
            State.learningWordSetIndex = 0;
            State.learningWordSetSignature = nextSignature;
            resetCurrentLearningSetProgress();
        }

        State.wordsToIntroduce = getCurrentLearningWordSetIds();
        State.totalWordCount = State.wordsToIntroduce.length;

        // When in starred-only mode, purge any unstarred items from in-progress tracking.
        if (starMode === 'only') {
            const lookup = getStarredLookup();
            State.introducedWordIDs = State.introducedWordIDs.filter(function (id) { return !!lookup[id]; });
            State.wrongAnswerQueue = (State.wrongAnswerQueue || []).filter(function (item) {
                return item && lookup[item.id];
            });
            State.wordsToIntroduce = (State.wordsToIntroduce || []).filter(function (id) { return !!lookup[id]; });
            State.totalWordCount = State.wordsToIntroduce.length;
        }

        State.isLearningMode = true;
        State.isListeningMode = false;
        return true;
    }

    function getSetProgress() {
        ensureDefaults();
        const sets = getLearningWordSets();
        if (!sets.length) {
            return { current: 0, total: 0, hasNext: false };
        }
        const idx = getLearningWordSetIndex();
        return {
            current: idx + 1,
            total: sets.length,
            hasNext: idx < (sets.length - 1)
        };
    }

    function hasNextSet() {
        return !!getSetProgress().hasNext;
    }

    function startNextSet() {
        ensureDefaults();
        const sets = getLearningWordSets();
        if (!sets.length) return false;

        let nextIndex = getLearningWordSetIndex() + 1;
        while (nextIndex < sets.length) {
            const nextIds = normalizeWordIdList(sets[nextIndex]);
            if (nextIds.length) {
                State.learningWordSetIndex = nextIndex;
                resetCurrentLearningSetProgress();
                State.wordsToIntroduce = nextIds;
                State.totalWordCount = nextIds.length;
                syncProgressUI();
                return true;
            }
            nextIndex++;
        }

        return false;
    }

    // ---- choice count API (used by main.js) ----
    function getChoiceCount() {
        ensureDefaults();
        return applyConstraints(State.learningChoiceCount);
    }

    // ---- answer bookkeeping (used by main.js on click) ----
    function recordAnswerResult(wordId, isCorrect, hadWrongThisTurn = false) {
        ensureDefaults();
        const normalizedWordId = parseInt(wordId, 10) || wordId;

        if (isCorrect) {
            // Only count toward mastery on first try
            if (!hadWrongThisTurn) {
                State.wordCorrectCounts[normalizedWordId] = (State.wordCorrectCounts[normalizedWordId] || 0) + 1;
                removeFromWrongQueue(normalizedWordId);
            }
            State.wordsAnsweredSinceLastIntro.add(normalizedWordId);

            // streak up => maybe increase choices
            State.learningCorrectStreak += 1;
            const t = State.learningCorrectStreak;
            const target =
                (t >= 13) ? 6 :
                    (t >= 10) ? 5 :
                        (t >= 6) ? 4 :
                            (t >= 3) ? 3 : 2;

            State.learningChoiceCount = applyConstraints(Math.min(target, State.MAX_CHOICE_COUNT));
        } else {
            // Wrong: schedule with randomized reappear turn (1–3 turns later)
            scheduleWrong(normalizedWordId, State.turnIndex);
            State.learningCorrectStreak = 0;
            State.learningChoiceCount = applyConstraints(Math.max(State.MIN_CHOICE_COUNT, State.learningChoiceCount - 1));
        }

        State.currentChoiceCount = State.learningChoiceCount;
    }

    // --- helpers to avoid immediate repeats when only one hard word remains ---
    function pickSprinkleFiller(excludeId) {
        const mastered = State.introducedWordIDs.filter(id => id !== excludeId && (State.wordCorrectCounts[id] || 0) >= State.MIN_CORRECT_COUNT);
        const others = State.introducedWordIDs.filter(id => id !== excludeId);

        const pool = mastered.length ? mastered : others;
        if (!pool.length) return null;

        const pickId = pool[Math.floor(Math.random() * pool.length)];
        return wordObjectById(pickId);
    }

    // ---- selection (introduce or pick next quiz target) ----
    function selectTargetWord() {
        ensureDefaults();
        State.turnIndex++;

        const readyWrongEntries = readyWrongs();
        const hasReadyWrongs = readyWrongEntries.length > 0;
        const hasPendingWrongs = (State.wrongAnswerQueue && State.wrongAnswerQueue.length > 0);
        const everyoneAnsweredThisCycle =
            State.introducedWordIDs.length > 0 &&
            State.introducedWordIDs.every(id => State.wordsAnsweredSinceLastIntro.has(id));

        const notYetIntroduced = (State.wordsToIntroduce || []).filter(id => !State.introducedWordIDs.includes(id));
        const nothingLeftToIntroduce = notYetIntroduced.length === 0;

        // FINISH condition
        const allIntroducedMastered =
            State.introducedWordIDs.length > 0 &&
            State.introducedWordIDs.every(id => (State.wordCorrectCounts[id] || 0) >= State.MIN_CORRECT_COUNT);

        if (nothingLeftToIntroduce && !hasReadyWrongs && !hasPendingWrongs && allIntroducedMastered) {
            State.isIntroducingWord = false;
            return null; // main.js will show results
        }

        // Bootstrap: introduce up to TWO words initially, preferring a non-conflicting pair.
        if (State.introducedWordIDs.length < 2 && notYetIntroduced.length > 0) {
            const take = Math.min(2 - State.introducedWordIDs.length, notYetIntroduced.length);
            const ids = selectBootstrapIntroIds(notYetIntroduced, take);
            if (!ids.length) {
                State.isIntroducingWord = false;
                return null;
            }
            const words = ids.map(id => wordObjectById(id)).filter(Boolean);
            if (words.length) {
                State.isIntroducingWord = true;
                State.wordsAnsweredSinceLastIntro.clear();
                return words; // main.js handleWordIntroduction([...])
            }
        }

        // May we introduce a NEW word this turn?
        // Do not hard-cap introductions by a fixed number, or larger sets can get stuck forever.
        const mayIntroduce = !hasReadyWrongs && !hasPendingWrongs && everyoneAnsweredThisCycle && !nothingLeftToIntroduce;

        if (mayIntroduce) {
            const nextId = pickNextIntroId(notYetIntroduced, State.lastWordShownId);
            const word = wordObjectById(nextId);
            if (word) {
                State.isIntroducingWord = true;
                State.wordsAnsweredSinceLastIntro.clear();
                return [word]; // introduce exactly one after the bootstrap
            }
        }

        // Otherwise, choose a PRACTICE target within the introduced set
        State.isIntroducingWord = false;

        // Priority 1: a READY wrong (randomized schedule, avoid immediate repeat)
        const { id: readyWrongId, fallbackId: fallbackReadyWrongId } = pickReadyWrongWithSpacing(readyWrongEntries);
        if (readyWrongId) {
            const wrongWord = wordObjectById(readyWrongId);
            if (wrongWord) {
                // After showing a ready wrong, reschedule it again if answered wrong later (handled in recordAnswerResult)
                State.lastWordShownId = wrongWord.id;
                return wrongWord;
            }
        }

        // Priority 2: those not yet answered in this cycle
        const poolNotAnswered = State.introducedWordIDs.filter(id => !State.wordsAnsweredSinceLastIntro.has(id));
        if (poolNotAnswered.length) {
            const pick = pickWeightedId(poolNotAnswered, State.lastWordShownId);
            const word = wordObjectById(pick);
            if (word) {
                State.lastWordShownId = word.id;
                return word;
            }
        }

        // Priority 3: least-known (below mastery) — avoid immediate repeat via sprinkle
        const needPractice = State.introducedWordIDs.filter(id => (State.wordCorrectCounts[id] || 0) < State.MIN_CORRECT_COUNT);
        if (needPractice.length) {
            // if exactly one hard word remains and was just shown, sprinkle a filler
            if (needPractice.length === 1 && State.lastWordShownId === needPractice[0]) {
                const filler = pickSprinkleFiller(needPractice[0]);
                if (filler) {
                    State.lastWordShownId = filler.id;
                    return filler;
                }
            }
            const min = Math.min(...needPractice.map(id => State.wordCorrectCounts[id] || 0));
            const pool = needPractice.filter(id => (State.wordCorrectCounts[id] || 0) === min);
            const pick = pickWeightedId(pool, State.lastWordShownId);
            const word = wordObjectById(pick);
            if (word) {
                // try to avoid immediate repeat with a quick sprinkle
                if (word.id === State.lastWordShownId) {
                    const filler = pickSprinkleFiller(word.id);
                    if (filler) {
                        State.lastWordShownId = filler.id;
                        return filler;
                    }
                }
                State.lastWordShownId = word.id;
                return word;
            }
        }

        // Fallback: any introduced (try to avoid repeat)
        if (State.introducedWordIDs.length) {
            const id = pickWeightedId(State.introducedWordIDs, State.lastWordShownId);
            const word = wordObjectById(id);
            if (word) {
                State.lastWordShownId = word.id;
                return word;
            }
        }

        // If nothing else fit but a ready wrong was waiting, allow it now
        if (fallbackReadyWrongId) {
            const wrongFallback = wordObjectById(fallbackReadyWrongId);
            if (wrongFallback) {
                State.lastWordShownId = wrongFallback.id;
                return wrongFallback;
            }
        }

        // Nothing viable
        return null;
    }

    function getJQuery() {
        if (root.jQuery) return root.jQuery;
        if (typeof window !== 'undefined' && window.jQuery) return window.jQuery;
        return null;
    }

    function scheduleTimeout(context, fn, delay) {
        if (context && typeof context.setGuardedTimeout === 'function') {
            return context.setGuardedTimeout(fn, delay);
        }
        return setTimeout(fn, delay);
    }

    function introduceWords(words, context) {
        if (!State.isIntroducing()) {
            console.warn('introduceWords called but not in INTRODUCING_WORDS state');
            return;
        }

        const wordsArray = Array.isArray(words) ? words : [words];
        const $jq = getJQuery();

        if ($jq) {
            $jq('#ll-tools-flashcard').removeClass('audio-line-layout').empty();
            $jq('#ll-tools-flashcard-content').removeClass('audio-line-mode');
        } else if (typeof document !== 'undefined') {
            const container = document.getElementById('ll-tools-flashcard');
            if (container) {
                container.classList && container.classList.remove('audio-line-layout');
                container.innerHTML = '';
            }
            const content = document.getElementById('ll-tools-flashcard-content');
            if (content && content.classList) {
                content.classList.remove('audio-line-mode');
            }
        }

        Dom.restoreHeaderUI && Dom.restoreHeaderUI();
        Dom.disableRepeatButton && Dom.disableRepeatButton();
        syncProgressUI();

        const mode = getCurrentDisplayMode();
        const cfg = (Selection && typeof Selection.getCategoryConfig === 'function')
            ? Selection.getCategoryConfig(State.currentCategoryName)
            : {};
        const introMode = (cfg && cfg.prompt_type === 'image' && (mode === 'audio' || mode === 'text_audio'))
            ? 'image'
            : mode;

        Promise.all(wordsArray.map(word => FlashcardLoader.loadResourcesForWord(word, introMode, State.currentCategoryName, cfg))).then(function () {
            if (!State.isIntroducing()) {
                console.warn('State changed during word loading, aborting introduction');
                return;
            }

            wordsArray.forEach((word, index) => {
                const $card = Cards.appendWordToContainer(word, introMode, cfg.prompt_type || 'audio');
                if ($card && typeof $card.attr === 'function') {
                    $card.attr('data-word-index', index);
                }

                const introAudio = FlashcardAudio.selectBestAudio(word, ['introduction']);
                const isoAudio = FlashcardAudio.selectBestAudio(word, ['isolation']);

                let audioPattern;
                if (introAudio && isoAudio && introAudio !== isoAudio) {
                    const useIntroFirst = Math.random() < 0.5;
                    audioPattern = useIntroFirst ? [introAudio, isoAudio, introAudio] : [isoAudio, introAudio, isoAudio];
                } else {
                    const singleAudio = introAudio || isoAudio || word.audio;
                    audioPattern = [singleAudio, singleAudio, singleAudio];
                }

                if ($card && typeof $card.attr === 'function') {
                    $card.attr('data-audio-pattern', JSON.stringify(audioPattern));
                }
            });

            if ($jq) {
                $jq('.flashcard-container').addClass('introducing').css('pointer-events', 'none');
                Dom.hideLoading && Dom.hideLoading();
                $jq('.flashcard-container').fadeIn(600);
            } else {
                Dom.hideLoading && Dom.hideLoading();
            }

            const timeoutId = scheduleTimeout(context, function () {
                playIntroductionSequence(wordsArray, 0, 0, context, 0);
            }, 650);
            State.addTimeout(timeoutId);
        }).catch(function (err) {
            console.error('Error preparing introductions:', err);
            State.forceTransitionTo(STATES.QUIZ_READY, 'Introduction error');
        });
    }

    function continueIntroductionSequence(words, wordIndex, repetition, context, options) {
        const opts = options || {};
        const currentWord = words[wordIndex];
        const countProgress = opts.countProgress !== false;
        const normalizedCurrentId = parseInt(currentWord && currentWord.id, 10) || (currentWord && currentWord.id);

        if (countProgress && normalizedCurrentId) {
            State.wordIntroductionProgress[normalizedCurrentId] =
                (State.wordIntroductionProgress[normalizedCurrentId] || 0) + 1;
            syncProgressUI();
        }

        if (repetition < State.AUDIO_REPETITIONS - 1) {
            const nextTimeoutId = scheduleTimeout(context, function () {
                if (!State.abortAllOperations) {
                    playIntroductionSequence(words, wordIndex, repetition + 1, context, 0);
                }
            }, INTRO_GAP_MS);
            State.addTimeout(nextTimeoutId);
            return;
        }

        if (normalizedCurrentId && !State.introducedWordIDs.includes(normalizedCurrentId)) {
            State.introducedWordIDs.push(normalizedCurrentId);
        }
        const nextTimeoutId = scheduleTimeout(context, function () {
            if (!State.abortAllOperations) {
                playIntroductionSequence(words, wordIndex + 1, 0, context, 0);
            }
        }, INTRO_WORD_GAP_MS);
        State.addTimeout(nextTimeoutId);
    }

    function playIntroductionSequence(words, wordIndex, repetition, context, retryCount) {
        const $jq = getJQuery();

        if (State.abortAllOperations || !State.isIntroducing()) {
            return;
        }

        if (wordIndex >= words.length) {
            Dom.enableRepeatButton && Dom.enableRepeatButton();
            if ($jq) {
                $jq('.flashcard-container').removeClass('introducing introducing-active').addClass('fade-out');
            }
            State.isIntroducingWord = false;

            const timeoutId = scheduleTimeout(context, function () {
                if (!State.abortAllOperations) {
                    State.transitionTo(STATES.QUIZ_READY, 'Introduction complete');
                    if (context && typeof context.startQuizRound === 'function') {
                        context.startQuizRound();
                    }
                }
            }, 300);
            State.addTimeout(timeoutId);
            return;
        }

        const currentWord = words[wordIndex];
        let $currentCard = null;
        if ($jq) {
            $currentCard = $jq('.flashcard-container[data-word-index="' + wordIndex + '"]');
            if (!$currentCard.length) {
                console.warn('Card disappeared during introduction');
                return;
            }

            $jq('.flashcard-container').removeClass('introducing-active');
            $currentCard.addClass('introducing-active');
        }

        const timeoutId = scheduleTimeout(context, function () {
            if (State.abortAllOperations || !State.isIntroducing()) return;

            let audioPattern = [];
            if ($currentCard && typeof $currentCard.attr === 'function') {
                const raw = $currentCard.attr('data-audio-pattern') || '[]';
                try { audioPattern = JSON.parse(raw); } catch (_) { audioPattern = []; }
            }
            const audioUrl = audioPattern[repetition] || audioPattern[0];
            const retriesUsed = Math.max(0, parseInt(retryCount, 10) || 0);
            const managedAudio = FlashcardAudio.createIntroductionAudio
                ? FlashcardAudio.createIntroductionAudio(audioUrl)
                : null;

            if (!managedAudio) {
                console.error('Failed to create introduction audio');
                continueIntroductionSequence(words, wordIndex, repetition, context, { countProgress: false });
                return;
            }

            if (Dom && typeof Dom.bindRepeatButtonAudio === 'function') {
                Dom.bindRepeatButtonAudio(managedAudio.audio || null);
                if (Dom.setRepeatButton) Dom.setRepeatButton('stop');
            }

            managedAudio.playUntilEnd()
                .then(() => {
                    if (State.abortAllOperations || !managedAudio.isValid() || !State.isIntroducing()) {
                        managedAudio.cleanup();
                        return;
                    }
                    managedAudio.cleanup();
                    continueIntroductionSequence(words, wordIndex, repetition, context, { countProgress: true });
                })
                .catch(err => {
                    console.warn('Audio play failed during introduction:', err);
                    managedAudio.cleanup();
                    if (retriesUsed < INTRO_AUDIO_RETRY_LIMIT) {
                        const retryDelay = 180 * (retriesUsed + 1);
                        const retryTimeoutId = scheduleTimeout(context, function () {
                            if (!State.abortAllOperations) {
                                playIntroductionSequence(words, wordIndex, repetition, context, retriesUsed + 1);
                            }
                        }, retryDelay);
                        State.addTimeout(retryTimeoutId);
                        return;
                    }
                    console.warn('Skipping failed introduction clip after retries', {
                        wordId: currentWord && currentWord.id ? currentWord.id : null,
                        repetition: repetition
                    });
                    continueIntroductionSequence(words, wordIndex, repetition, context, { countProgress: false });
                });
        }, 100);
        State.addTimeout(timeoutId);
    }

    function onFirstRoundStart() {
        ensureDefaults();
        const hasProgress =
            (Array.isArray(State.introducedWordIDs) && State.introducedWordIDs.length > 0) ||
            (State.wordCorrectCounts && Object.keys(State.wordCorrectCounts).length > 0) ||
            (Array.isArray(State.wrongAnswerQueue) && State.wrongAnswerQueue.length > 0);
        if (!hasProgress) {
            initialize();
        }
        syncProgressUI();
        return true;
    }

    function onCorrectAnswer(ctx) {
        if (!ctx || !ctx.targetWord) return;
        recordAnswerResult(ctx.targetWord.id, true, ctx.hadWrongThisTurn);
        State.isIntroducingWord = false;
        return true;
    }

    function onWrongAnswer(ctx) {
        if (!ctx || !ctx.targetWord) return;
        recordAnswerResult(ctx.targetWord.id, false);
        return true;
    }

    function handleNoTarget(utils) {
        ensureDefaults();
        if (State.isFirstRound && State.totalWordCount === 0) {
            if (utils && typeof utils.showLoadingError === 'function') {
                utils.showLoadingError();
            }
            return true;
        }
        State.transitionTo(STATES.SHOWING_RESULTS, 'Quiz complete');
        if (Results && typeof Results.showResults === 'function') {
            Results.showResults();
        }
        return true;
    }

    function handlePostSelection(selection, context) {
        ensureDefaults();
        syncProgressUI();

        if (State.isIntroducingWord) {
            if (!State.canIntroduceWords()) {
                console.warn('Cannot introduce words in current state:', State.getState ? State.getState() : '');
                State.isIntroducingWord = false;
                return false;
            }
            State.transitionTo(STATES.INTRODUCING_WORDS, 'Starting word introduction');
            introduceWords(selection, context);
            return true;
        }
        return false;
    }

    function beforeOptionsFill() {
        ensureDefaults();
        const choiceCount = getChoiceCount();
        State.currentChoiceCount = choiceCount;
        if (root.FlashcardOptions && typeof root.FlashcardOptions.initializeOptionsCount === 'function') {
            root.FlashcardOptions.initializeOptionsCount(choiceCount);
        }
        syncProgressUI();
        return true;
    }

    function configureTargetAudio(target) {
        if (State.isIntroducingWord || !target) return;
        const questionAudio = FlashcardAudio.selectBestAudio(target, ['question', 'isolation', 'introduction']);
        if (questionAudio) target.audio = questionAudio;
    }

    // Public API
    root.LLFlashcards.Modes = root.LLFlashcards.Modes || {};
    root.LLFlashcards.Modes.Learning = {
        initialize,
        getChoiceCount,
        recordAnswerResult,
        selectTargetWord,
        getSetProgress,
        hasNextSet,
        startNextSet,
        onCorrectAnswer,
        onWrongAnswer,
        onFirstRoundStart,
        handleNoTarget,
        handlePostSelection,
        beforeOptionsFill,
        configureTargetAudio
    };

    // Back-compat alias for existing calls in main.js
    root.LLFlashcards.LearningMode = root.LLFlashcards.Modes.Learning;

})(window);
