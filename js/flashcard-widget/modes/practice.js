(function (root) {
    'use strict';

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Modes = root.LLFlashcards.Modes || {};

    root.LLFlashcards.Modes.Practice = {
        initialize: function () {
            const S = (root.LLFlashcards.State = root.LLFlashcards.State || {});
            S.isLearningMode = false;
            S.isListeningMode = false;
            return true;
        },
        // Not used in practice; FlashcardOptions.calculateNumberOfOptions controls the count.
        getChoiceCount: function () { return null; },
        // No bookkeeping here; practice option changes come from FlashcardOptions + wrongIndexes.
        recordAnswerResult: function () { }
    };
})(window);
