(function (root, $) {
    'use strict';
    const { State, Dom, Effects } = root.LLFlashcards;

    function hideResults() { $('#quiz-results').hide(); $('#restart-quiz').hide(); }

    function showResults() {
        const msgs = root.llToolsFlashcardsMessages || {};
        const total = State.quizResults.correctOnFirstTry + State.quizResults.incorrect.length;

        if (total === 0) {
            $('#quiz-results-title').text(msgs.somethingWentWrong || 'Something went wrong');
            $('#quiz-results-message').hide();
            $('#quiz-results').show();
            $('#restart-quiz').hide();
            root.FlashcardAudio.playFeedback(false, null, null);
            return;
        }
        $('#quiz-results').show();
        Dom.hideLoading();
        $('#ll-tools-repeat-flashcard').hide();
        $('#ll-tools-category-stack, #ll-tools-category-display').hide();

        $('#correct-count').text(State.quizResults.correctOnFirstTry);
        $('#total-questions').text(total);
        $('#restart-quiz').show();

        if (Array.isArray(State.categoryNames) && State.categoryNames.length) {
            $('#quiz-results-categories').text('Categories: ' + State.categoryNames.join(', ')).show();
        }

        const ratio = total > 0 ? (State.quizResults.correctOnFirstTry / total) : 0;
        const $title = $('#quiz-results-title'), $msg = $('#quiz-results-message');

        if (ratio === 1) { $title.text(msgs.perfect || 'Perfect!'); $msg.hide(); }
        else if (ratio >= 0.7) { $title.text(msgs.goodJob || 'Good job!'); $msg.hide(); }
        else {
            $title.text(msgs.keepPracticingTitle || 'Keep practicing!');
            $msg.text(msgs.keepPracticingMessage || 'You’re on the right track...').css({ fontSize: '14px', marginTop: '10px', color: '#555' }).show();
        }

        if (ratio >= 0.7) Effects.startConfetti();
    }

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Results = { showResults, hideResults };
})(window, jQuery);
