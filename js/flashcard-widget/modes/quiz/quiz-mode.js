(function (root, $) {
    'use strict';

    const ModeBase = root.LLFlashcards.ModeBase;
    const State = root.LLFlashcards.State;
    const QuizState = root.LLFlashcards.QuizState;
    const Selection = root.LLFlashcards.Selection;
    const Dom = root.LLFlashcards.Dom;
    const Cards = root.LLFlashcards.Cards;
    const Effects = root.LLFlashcards.Effects;

    /**
     * QuizMode - Standard quiz mode implementation
     */
    class QuizMode extends ModeBase {
        constructor(config = {}) {
            super(config);
            this.variant = config.variant || 'audio-to-image';
        }

        async initialize() {
            QuizState.reset();

            if (root.FlashcardOptions?.initializeOptionsCount) {
                const defaultOptions = this.config.defaultOptions || 2;
                root.FlashcardOptions.initializeOptionsCount(defaultOptions);
            }

            return Promise.resolve();
        }

        async start() {
            return this.startRound();
        }

        async cleanup() {
            QuizState.reset();
            return Promise.resolve();
        }

        getNextItem() {
            const QuizSelection = root.LLFlashcards.QuizSelection;
            const QuizOptions = root.LLFlashcards.QuizOptions;

            QuizOptions.calculateNumberOfOptions(
                QuizState.wrongIndexes,
                QuizState.isFirstRound,
                State.currentCategoryName
            );

            const target = QuizSelection.selectTargetWordAndCategory();

            if (!target) {
                if (QuizState.isFirstRound) {
                    let hasWords = false;
                    for (let catName of State.categoryNames) {
                        if (State.wordsByCategory[catName] && State.wordsByCategory[catName].length > 0) {
                            hasWords = true;
                            break;
                        }
                    }
                    if (!hasWords) {
                        return null;
                    }
                }
                return null;
            }

            return target;
        }

        async handleInteraction(action, data) {
            if (action === 'correct-answer') {
                return this.handleCorrectAnswer(data.targetWord, data.$card);
            } else if (action === 'wrong-answer') {
                return this.handleWrongAnswer(data.targetWord, data.index, data.$card);
            }
        }

        async handleCorrectAnswer(targetWord, $correctCard) {
            if (!State.canProcessAnswer()) {
                console.warn('Cannot process answer in state:', State.getState());
                return;
            }
            if (State.userClickedCorrectAnswer) return;

            State.transitionTo(State.STATES.PROCESSING_ANSWER, 'Correct answer');
            $correctCard.addClass('correct-answer');

            const rect = $correctCard[0].getBoundingClientRect();
            Effects.startConfetti({
                particleCount: 20,
                angle: 90,
                spread: 60,
                origin: {
                    x: (rect.left + rect.width / 2) / window.innerWidth,
                    y: (rect.top + rect.height / 2) / window.innerHeight
                },
                duration: 50
            });

            State.userClickedCorrectAnswer = true;

            QuizState.recordCorrectAnswer(
                targetWord,
                QuizState.wrongIndexes.length > 0,
                State.currentCategoryName,
                State.categoryRoundCount[State.currentCategoryName] || 0
            );

            root.FlashcardAudio.playFeedback(true, null, () => {
                $('.flashcard-container').not($correctCard).addClass('fade-out');
                setTimeout(() => {
                    QuizState.isFirstRound = false;
                    State.userClickedCorrectAnswer = false;
                    State.transitionTo(State.STATES.QUIZ_READY, 'Ready for next question');
                    this.startRound();
                }, 600);
            });
        }

        async handleWrongAnswer(targetWord, index, $wrongCard) {
            if (!State.canProcessAnswer()) {
                console.warn('Cannot process answer in state:', State.getState());
                return;
            }
            if (State.userClickedCorrectAnswer) return;

            QuizState.recordWrongAnswer(
                targetWord,
                State.currentCategoryName,
                State.categoryRoundCount[State.currentCategoryName] || 0
            );

            QuizState.wrongIndexes.push(index);

            root.FlashcardAudio.playFeedback(false, targetWord.audio, null);
            $wrongCard.addClass('fade-out').one('transitionend', function () { $wrongCard.remove(); });

            const mode = Selection.getCurrentDisplayMode();
            if (QuizState.wrongIndexes.length === 2) {
                $('.flashcard-container').not(function () {
                    return $(this).attr('data-word-id') === String(targetWord.id);
                }).addClass('fade-out').one('transitionend', function () { $(this).remove(); });
            }
        }

        async startRound() {
            if (!State.canStartQuizRound()) {
                console.warn('Cannot start quiz round in state:', State.getState());
                return;
            }

            State.clearActiveTimeouts();
            $('#ll-tools-flashcard').empty();
            Dom.restoreHeaderUI();

            root.FlashcardAudio.pauseAllAudio();
            Dom.showLoading();
            root.FlashcardAudio.setTargetAudioHasPlayed(false);
            State.hadWrongAnswerThisTurn = false;

            const target = this.getNextItem();

            if (!target) {
                State.transitionTo(State.STATES.SHOWING_RESULTS, 'Quiz complete');
                if (root.LLFlashcards?.Results) {
                    root.LLFlashcards.Results.showResults();
                }
                return;
            }

            try {
                await root.FlashcardLoader.loadResourcesForWord(target, Selection.getCurrentDisplayMode());
                Selection.fillQuizOptions(target);
                await root.FlashcardAudio.setTargetWordAudio(target);
                Dom.hideLoading();
                Dom.enableRepeatButton();
                State.transitionTo(State.STATES.SHOWING_QUESTION, 'Question displayed');
            } catch (err) {
                console.error('Error in quiz round:', err);
                State.forceTransitionTo(State.STATES.QUIZ_READY, 'Error recovery');
            }
        }

        isComplete() {
            return this.getNextItem() === null;
        }

        getDisplayConfig() {
            return {
                showRepeatButton: true,
                showProgressBar: false,
                showCategoryDisplay: true,
                showModeSwitcher: true,
                interactionType: 'click'
            };
        }

        getResults() {
            return QuizState.quizResults;
        }

        getModeName() {
            return 'quiz';
        }
    }

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.QuizMode = QuizMode;
})(window, jQuery);