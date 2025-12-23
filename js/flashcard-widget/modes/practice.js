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

    function normalizeStarMode(mode) {
        const val = (mode || '').toString();
        return (val === 'only' || val === 'normal' || val === 'weighted') ? val : 'normal';
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

    function initialize() {
        State.isLearningMode = false;
        State.isListeningMode = false;
        State.completedCategories = {};
        State.categoryRepetitionQueues = {};
        State.practiceForcedReplays = {};
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
        if (State.wrongIndexes.length === 0) return;
        queueForRepetition(ctx.targetWord, { force: true });
    }

    function onWrongAnswer(ctx) {
        if (!ctx || !ctx.targetWord) return;
        incrementForcedReplayCount(ctx.targetWord.id);
        queueForRepetition(ctx.targetWord, { force: true });
    }

    function onFirstRoundStart() {
        return true;
    }

    function selectTargetWord() {
        FlashcardOptions.calculateNumberOfOptions(State.wrongIndexes, State.isFirstRound, State.currentCategoryName);
        const picked = Selection.selectTargetWordAndCategory();
        const starMode = getStarMode();
        if (picked && starMode === 'weighted' && isStarred(picked.id)) {
            queueForRepetition(picked);
        }
        return picked;
    }

    function handleNoTarget(ctx) {
        if (State.isFirstRound) {
            const hasWords = (State.categoryNames || []).some(name => {
                const words = State.wordsByCategory && State.wordsByCategory[name];
                return Array.isArray(words) && words.length > 0;
            });
            if (!hasWords) {
                if (ctx && typeof ctx.showLoadingError === 'function') {
                    ctx.showLoadingError();
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
            State.completedCategories = State.completedCategories || {};
            queuedCategories.forEach(function (cat) {
                State.completedCategories[cat] = false;
                if (!State.categoryNames.includes(cat)) {
                    State.categoryNames.push(cat);
                }
            });
            State.isFirstRound = false;
            if (ctx && typeof ctx.startQuizRound === 'function') {
                setTimeout(function () { ctx.startQuizRound(); }, 0);
            }
            return true;
        }

        State.transitionTo && State.transitionTo(STATES.SHOWING_RESULTS, 'Quiz complete');
        Results.showResults && Results.showResults();
        return true;
    }

    function beforeOptionsFill() {
        return true;
    }

    function configureTargetAudio(target) {
        // For audio prompts, prefer the question clip, then isolation, then introduction
        if (!target) return true;
        const promptType = State.currentPromptType || 'audio';
        if (promptType !== 'audio') return true;
        if (!root.FlashcardAudio || typeof root.FlashcardAudio.selectBestAudio !== 'function') return true;

        const preferredOrder = ['question', 'isolation', 'introduction'];
        const best = root.FlashcardAudio.selectBestAudio(target, preferredOrder);
        if (best) {
            target.audio = best;
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
        configureTargetAudio
    };
})(window);
