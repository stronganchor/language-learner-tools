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
        const prefs = root.llToolsStudyPrefs || {};
        const modeFromPrefs = prefs.starMode || prefs.star_mode;
        const modeFromFlash = (root.llToolsFlashcardsData && (root.llToolsFlashcardsData.starMode || root.llToolsFlashcardsData.star_mode)) || null;
        const mode = modeFromPrefs || modeFromFlash || 'weighted';
        return mode === 'only' ? 'only' : 'weighted';
    }

    function isStarred(wordId) {
        if (!wordId) { return false; }
        const lookup = getStarredLookup();
        return !!lookup[wordId];
    }

    function initialize() {
        State.isLearningMode = false;
        State.isListeningMode = false;
        State.completedCategories = {};
        return true;
    }

    function queueForRepetition(targetWord, options) {
        const categoryName = State.currentCategoryName;
        if (!categoryName || !targetWord) return;
        const queue = (State.categoryRepetitionQueues[categoryName] = State.categoryRepetitionQueues[categoryName] || []);
        const alreadyQueued = queue.some(item => item.wordData.id === targetWord.id);
        if (alreadyQueued) return;

        const starredLookup = getStarredLookup();
        const isStarredWord = !!starredLookup[targetWord.id];
        const force = !!(options && options.force);
        const starMode = getStarMode();

        // Avoid endlessly re-queuing starred words once they've hit their allowed plays
        State.starPlayCounts = State.starPlayCounts || {};
        const plays = State.starPlayCounts[targetWord.id] || 0;
        const maxUses = (starMode === 'weighted' && isStarredWord) ? 2 : 1;
        if (!force && isStarredWord && plays >= maxUses) {
            return;
        }

        const base = State.categoryRoundCount[categoryName] || 0;
        const offset = isStarred(targetWord.id)
            ? ((Util && typeof Util.randomInt === 'function') ? Util.randomInt(2, 3) : (Math.floor(Math.random() * 2) + 2)) // delay slightly to avoid immediate repeat
            : ((Util && typeof Util.randomInt === 'function') ? Util.randomInt(2, 4) : (Math.floor(Math.random() * 3) + 2));
        queue.push({
            wordData: targetWord,
            reappearRound: base + offset
        });
    }

    function onCorrectAnswer(ctx) {
        if (!ctx || !ctx.targetWord) return;
        if (State.wrongIndexes.length === 0) return;
        queueForRepetition(ctx.targetWord, { force: true });
    }

    function onWrongAnswer(ctx) {
        if (!ctx || !ctx.targetWord) return;
        queueForRepetition(ctx.targetWord, { force: true });
    }

    function onFirstRoundStart() {
        return true;
    }

    function selectTargetWord() {
        FlashcardOptions.calculateNumberOfOptions(State.wrongIndexes, State.isFirstRound, State.currentCategoryName);
        const picked = Selection.selectTargetWordAndCategory();
        if (picked && isStarred(picked.id)) {
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
