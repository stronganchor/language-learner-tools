(function (root) {
    'use strict';

    const S = (root.LLFlashcards = root.LLFlashcards || {}).State || {};

    function initialize() {
        S.isLearningMode = false;
        S.isListeningMode = true;
        return true;
    }

    function getChoiceCount() {
        // No choices in listening mode
        return 0;
    }

    function recordAnswerResult() {
        // No scoring in passive listening (for now)
        return {};
    }

    function selectTargetWord() {
        // Cycle deterministically for now
        if (!Array.isArray(S.wordsLinear)) {
            const all = [];
            if (S.categoryNames && S.wordsByCategory) {
                for (const name of S.categoryNames) {
                    const list = S.wordsByCategory[name] || [];
                    for (const w of list) all.push(w);
                }
            }
            S.wordsLinear = all;
            S.listenIndex = 0;
        }
        if (!S.wordsLinear.length) return null;
        const word = S.wordsLinear[S.listenIndex % S.wordsLinear.length];
        S.listenIndex++;
        return word;
    }

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Modes = root.LLFlashcards.Modes || {};
    root.LLFlashcards.Modes.Listening = {
        initialize,
        getChoiceCount,
        recordAnswerResult,
        selectTargetWord
    };

})(window);
