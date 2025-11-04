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

    function initialize() {
        State.isLearningMode = false;
        State.isListeningMode = false;
        return true;
    }

    function queueForRepetition(targetWord) {
        const categoryName = State.currentCategoryName;
        if (!categoryName || !targetWord) return;

        const queue = (State.categoryRepetitionQueues[categoryName] = State.categoryRepetitionQueues[categoryName] || []);
        const alreadyQueued = queue.some(item => item.wordData.id === targetWord.id);
        if (alreadyQueued) return;

        const base = State.categoryRoundCount[categoryName] || 0;
        const offset = (Util && typeof Util.randomInt === 'function') ? Util.randomInt(1, 3) : (Math.floor(Math.random() * 3) + 1);
        queue.push({
            wordData: targetWord,
            reappearRound: base + offset
        });
    }

    function onCorrectAnswer(ctx) {
        if (!ctx || !ctx.targetWord) return;
        if (State.wrongIndexes.length === 0) return;
        queueForRepetition(ctx.targetWord);
    }

    function onWrongAnswer(ctx) {
        if (!ctx || !ctx.targetWord) return;
        queueForRepetition(ctx.targetWord);
    }

    function onFirstRoundStart() {
        return true;
    }

    function selectTargetWord() {
        FlashcardOptions.calculateNumberOfOptions(State.wrongIndexes, State.isFirstRound, State.currentCategoryName);
        return Selection.selectTargetWordAndCategory();
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

    function configureTargetAudio() {
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
