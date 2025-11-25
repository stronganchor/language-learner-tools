(function (root, $) {
    'use strict';
    const { State, Dom, Effects } = root.LLFlashcards;

    const CHECKMARK_SVG = `
        <svg class="ll-learning-checkmark" width="80" height="80" viewBox="0 0 52 52" xmlns="http://www.w3.org/2000/svg">
            <circle class="ll-checkmark-circle" cx="26" cy="26" r="25" fill="none"/>
            <path class="ll-checkmark-check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
        </svg>
    `;

    function insertCompletionCheckmark() {
        $('.ll-learning-checkmark').remove();
        $('#quiz-results-title').before(CHECKMARK_SVG);
    }

    function removeCompletionCheckmark() {
        $('.ll-learning-checkmark').remove();
    }

    function isLearningSupportedForResults() {
        try {
            const Selection = root.LLFlashcards && root.LLFlashcards.Selection;
            const categories = State && State.categoryNames;
            if (Selection && typeof Selection.isLearningSupportedForCategories === 'function') {
                return Selection.isLearningSupportedForCategories(categories);
            }
            if (Selection && typeof Selection.getCategoryConfig === 'function' &&
                Array.isArray(categories) && categories.length) {
                return categories.every(function (name) {
                    const cfg = Selection.getCategoryConfig(name);
                    return cfg.learning_supported !== false;
                });
            }
        } catch (_) { /* no-op */ }
        return true;
    }

    function hideResults() {
        $('#quiz-results').hide();
        $('#restart-quiz').hide();
        $('#quiz-mode-buttons').hide();
        $('#restart-practice-mode, #restart-learning-mode').show();
        $('#restart-listening-mode').hide();
        removeCompletionCheckmark();
        $('#ll-tools-flashcard').show();
    }

    function showResults() {
        const msgs = root.llToolsFlashcardsMessages || {};
        const State = root.LLFlashcards.State;
        const learningAllowed = isLearningSupportedForResults();

        $('#ll-tools-flashcard').hide();
        // Hide listening mode controls if present
        $('#ll-tools-listening-controls').hide();
        removeCompletionCheckmark();

        if (root.FlashcardAudio) {
            try {
                const pausePromise = typeof root.FlashcardAudio.pauseAllAudio === 'function'
                    ? root.FlashcardAudio.pauseAllAudio()
                    : null;
                if (pausePromise && typeof pausePromise.catch === 'function') {
                    pausePromise.catch(err => console.warn('Audio pause failed during results display:', err));
                }
            } catch (err) {
                console.warn('Error pausing audio during results display:', err);
            }

            if (typeof root.FlashcardAudio.startNewSession === 'function') {
                root.FlashcardAudio.startNewSession().catch(err => {
                    console.warn('Audio cleanup session failed during results display:', err);
                });
            }
        }

        // Keep mode switch buttons available during results

        if (State.isLearningMode) {
            // Insert checkmark BEFORE the title
            $('#quiz-results-title').text(msgs.learningComplete || 'Learning Complete!');

            insertCompletionCheckmark();

            $('#quiz-results-message').hide();
            $('#correct-count').parent().hide();
            $('#quiz-results').show();
            $('#restart-quiz').hide();
            $('#quiz-mode-buttons').show();
            $('#restart-practice-mode, #restart-learning-mode').show();
            $('#restart-listening-mode').hide();
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

        // Listening mode: simple results (no confetti)
        if (State.isListeningMode) {
            const msgs2 = root.llToolsFlashcardsMessages || {};
            $('#quiz-results-title').text(msgs2.listeningComplete || 'Listening Complete');
            insertCompletionCheckmark();
            $('#quiz-results-message').hide();
            $('#correct-count').parent().hide();
            $('#quiz-results-categories').show();
            $('#quiz-results').show();
            $('#restart-quiz').hide();
            // Only show the replay listening button; hide the cross-mode buttons here
            $('#quiz-mode-buttons').show();
            $('#restart-practice-mode').hide();
            $('#restart-learning-mode').hide();
            $('#restart-listening-mode').show();
            Dom.hideLoading();
            $('#ll-tools-repeat-flashcard').hide();
            $('#ll-tools-category-stack, #ll-tools-category-display').hide();
            $('#ll-tools-learning-progress').hide();
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
            // Avoid playing feedback if the widget is closing/idle
            try {
                var isClosing = false;
                try { isClosing = !!(State && typeof State.is === 'function' && State.is(State.STATES.CLOSING)); } catch (_) { isClosing = false; }
                if (!isClosing && State && State.widgetActive && root.FlashcardAudio && typeof root.FlashcardAudio.playFeedback === 'function') {
                    root.FlashcardAudio.playFeedback(false, null, null);
                }
            } catch (_) { }
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
        $('#restart-practice-mode').show();
        if (learningAllowed) {
            $('#restart-learning-mode').show();
        } else {
            $('#restart-learning-mode').hide();
        }
        $('#restart-listening-mode').hide();
        removeCompletionCheckmark();

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
