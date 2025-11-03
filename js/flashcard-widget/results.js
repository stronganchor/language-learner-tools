(function (root, $) {
    'use strict';
    const { State, Dom, Effects } = root.LLFlashcards;

    function hideResults() {
        $('#quiz-results').hide();
        $('#restart-quiz').hide();
        $('#quiz-mode-buttons').hide();
    }

    function showResults() {
        const msgs = root.llToolsFlashcardsMessages || {};
        const State = root.LLFlashcards.State;

        // Hide mode switcher during results
        $('#ll-tools-mode-switcher').hide();

        if (State.isLearningMode) {
            // Create animated SVG checkmark
            const checkmarkSVG = `
                <svg class="ll-learning-checkmark" width="80" height="80" viewBox="0 0 52 52" xmlns="http://www.w3.org/2000/svg">
                    <circle class="ll-checkmark-circle" cx="26" cy="26" r="25" fill="none"/>
                    <path class="ll-checkmark-check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
                </svg>
            `;

            // Insert checkmark BEFORE the title
            $('#quiz-results-title').text(msgs.learningComplete || 'Learning Complete!');

            // Remove any existing checkmark first, then insert new one before title
            $('.ll-learning-checkmark').remove();
            $('#quiz-results-title').before(checkmarkSVG);

            $('#quiz-results-message').hide();
            $('#correct-count').parent().hide();
            $('#quiz-results').show();
            $('#restart-quiz').hide();
            $('#quiz-mode-buttons').show();
            Dom.hideLoading();
            $('#ll-tools-repeat-flashcard').hide();
            $('#ll-tools-category-stack, #ll-tools-category-display').hide();
            $('#ll-tools-learning-progress').hide();

            const totalWords = Object.keys(State.wordCorrectCounts).length;
            const completedWords = Object.values(State.wordCorrectCounts).filter(count => count >= State.MIN_CORRECT_COUNT).length;

            if (completedWords === totalWords) {
                Effects.startConfetti();
            }
            return;
        }

        // Practice mode: hide learning progress bar
        $('#ll-tools-learning-progress').hide();

        const total = State.quizResults.correctOnFirstTry + State.quizResults.incorrect.length;

        if (total === 0) {
            $('#quiz-results-title').text(msgs.somethingWentWrong || 'Something went wrong');
            $('#quiz-results-message').hide();
            $('#quiz-results').show();
            $('#restart-quiz').hide();
            $('#quiz-mode-buttons').hide();
            root.FlashcardAudio.playFeedback(false, null, null);
            return;
        }

        $('#quiz-results').show();
        Dom.hideLoading();
        $('#ll-tools-repeat-flashcard').hide();
        $('#ll-tools-category-stack, #ll-tools-category-display').hide();

        $('#correct-count').text(State.quizResults.correctOnFirstTry);
        $('#total-questions').text(total);
        $('#correct-count').parent().show();
        $('#restart-quiz').hide();
        $('#quiz-mode-buttons').show();

        if (Array.isArray(State.categoryNames) && State.categoryNames.length) {
            const categoriesLabel = msgs.categoriesLabel || 'Categories';
            $('#quiz-results-categories').text(categoriesLabel + ': ' + State.categoryNames.join(', ')).show();
        }

        const ratio = total > 0 ? (State.quizResults.correctOnFirstTry / total) : 0;
        const $title = $('#quiz-results-title'), $msg = $('#quiz-results-message');

        if (ratio === 1) { $title.text(msgs.perfect || 'Perfect!'); $msg.hide(); }
        else if (ratio >= 0.7) { $title.text(msgs.goodJob || 'Good job!'); $msg.hide(); }
        else {
            $title.text(msgs.keepPracticingTitle || 'Keep practicing!');
            $msg.text(msgs.keepPracticingMessage || "You're on the right track...").css({ fontSize: '14px', marginTop: '10px', color: '#555' }).show();
        }

        if (ratio >= 0.7) Effects.startConfetti();
    }

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Results = { showResults, hideResults };
})(window, jQuery);