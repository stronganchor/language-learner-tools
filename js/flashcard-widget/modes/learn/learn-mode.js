(function (root, $) {
    'use strict';

    const ModeBase = root.LLFlashcards.ModeBase;
    const State = root.LLFlashcards.State;
    const LearnState = root.LLFlashcards.LearnState;
    const Selection = root.LLFlashcards.Selection;
    const Dom = root.LLFlashcards.Dom;
    const Cards = root.LLFlashcards.Cards;
    const Effects = root.LLFlashcards.Effects;

    const INTRO_GAP_MS = 700;
    const INTRO_WORD_GAP_MS = 1200;

    /**
     * LearnMode - Learning mode with word introductions and progressive difficulty
     */
    class LearnMode extends ModeBase {
        constructor(config = {}) {
            super(config);
        }

        async initialize() {
            LearnState.reset();

            const LearnSelection = root.LLFlashcards.LearnSelection;
            if (LearnSelection?.initializeLearningMode) {
                LearnSelection.initializeLearningMode();
            }

            return Promise.resolve();
        }

        async start() {
            return this.startRound();
        }

        async cleanup() {
            LearnState.reset();
            $('#ll-tools-learning-progress').hide().empty();
            return Promise.resolve();
        }

        getNextItem() {
            const LearnSelection = root.LLFlashcards.LearnSelection;
            const target = LearnSelection.selectLearningModeWord();

            if (!target) {
                if (State.isFirstRound && LearnState.totalWordCount === 0) {
                    return null;
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

            const LearnSelection = root.LLFlashcards.LearnSelection;
            if (LearnSelection?.recordAnswerResult) {
                LearnSelection.recordAnswerResult(
                    targetWord.id,
                    true,
                    LearnState.hadWrongAnswerThisTurn
                );
            }

            LearnState.isIntroducingWord = false;

            root.FlashcardAudio.playFeedback(true, null, () => {
                $('.flashcard-container').not($correctCard).addClass('fade-out');
                setTimeout(() => {
                    State.isFirstRound = false;
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

            const LearnSelection = root.LLFlashcards.LearnSelection;
            if (LearnSelection?.recordAnswerResult) {
                LearnSelection.recordAnswerResult(targetWord.id, false);
            }

            LearnState.hadWrongAnswerThisTurn = true;
            root.FlashcardAudio.playFeedback(false, targetWord.audio, null);
            $wrongCard.addClass('fade-out').one('transitionend', function () { $wrongCard.remove(); });
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
            LearnState.hadWrongAnswerThisTurn = false;

            const target = this.getNextItem();

            Dom.updateLearningProgress(
                LearnState.introducedWordIDs.length,
                LearnState.totalWordCount,
                LearnState.wordCorrectCounts,
                LearnState.wordIntroductionProgress
            );

            if (!target) {
                State.transitionTo(State.STATES.SHOWING_RESULTS, 'Quiz complete');
                if (root.LLFlashcards?.Results) {
                    root.LLFlashcards.Results.showResults();
                }
                return;
            }

            if (LearnState.isIntroducingWord) {
                if (!State.canIntroduceWords()) {
                    console.warn('Cannot introduce words in state:', State.getState());
                    return;
                }
                State.transitionTo(State.STATES.INTRODUCING_WORDS, 'Starting word introduction');
                this.handleWordIntroduction(target);
                return;
            }

            try {
                await root.FlashcardLoader.loadResourcesForWord(target, Selection.getCurrentDisplayMode());

                const LearnSelection = root.LLFlashcards.LearnSelection;
                if (LearnSelection?.getChoiceCount) {
                    const choiceCount = LearnSelection.getChoiceCount();
                    State.currentChoiceCount = choiceCount;
                    if (root.FlashcardOptions?.initializeOptionsCount) {
                        root.FlashcardOptions.initializeOptionsCount(choiceCount);
                    }
                }

                Selection.fillQuizOptions(target);

                const questionAudio = root.FlashcardAudio.selectBestAudio(
                    target,
                    ['question', 'isolation', 'introduction']
                );
                if (questionAudio) target.audio = questionAudio;

                await root.FlashcardAudio.setTargetWordAudio(target);
                Dom.hideLoading();
                Dom.enableRepeatButton();
                State.transitionTo(State.STATES.SHOWING_QUESTION, 'Question displayed');
            } catch (err) {
                console.error('Error in learning round:', err);
                State.forceTransitionTo(State.STATES.QUIZ_READY, 'Error recovery');
            }
        }

        handleWordIntroduction(words) {
            if (!State.isIntroducing()) {
                console.warn('handleWordIntroduction called but not in INTRODUCING_WORDS state');
                return;
            }

            const wordsArray = Array.isArray(words) ? words : [words];

            $('#ll-tools-flashcard').empty();
            Dom.restoreHeaderUI();
            Dom.disableRepeatButton();

            Dom.updateLearningProgress(
                LearnState.introducedWordIDs.length,
                LearnState.totalWordCount,
                LearnState.wordCorrectCounts,
                LearnState.wordIntroductionProgress
            );

            const mode = Selection.getCurrentDisplayMode();

            Promise.all(wordsArray.map(word => {
                return root.FlashcardLoader.loadResourcesForWord(word, mode);
            })).then(() => {
                if (!State.isIntroducing()) {
                    console.warn('State changed during word loading, aborting introduction');
                    return;
                }

                wordsArray.forEach((word, index) => {
                    const $card = Cards.appendWordToContainer(word);
                    $card.attr('data-word-index', index);

                    const introAudio = root.FlashcardAudio.selectBestAudio(word, ['introduction']);
                    const isoAudio = root.FlashcardAudio.selectBestAudio(word, ['isolation']);

                    let audioPattern;
                    if (introAudio && isoAudio && introAudio !== isoAudio) {
                        const useIntroFirst = Math.random() < 0.5;
                        audioPattern = useIntroFirst
                            ? [introAudio, isoAudio, introAudio]
                            : [isoAudio, introAudio, isoAudio];
                    } else {
                        const singleAudio = introAudio || isoAudio || word.audio;
                        audioPattern = [singleAudio, singleAudio, singleAudio];
                    }

                    $card.attr('data-audio-pattern', JSON.stringify(audioPattern));
                });

                $('.flashcard-container').addClass('introducing').css('pointer-events', 'none');
                Dom.hideLoading();
                $('.flashcard-container').fadeIn(600);

                const timeoutId = setTimeout(() => {
                    this.playIntroductionSequence(wordsArray, 0, 0);
                }, 650);
                State.addTimeout(timeoutId);
            });
        }

        playIntroductionSequence(words, wordIndex, repetition) {
            if (State.abortAllOperations || !State.isIntroducing()) {
                console.log('Introduction sequence aborted');
                return;
            }

            if (wordIndex >= words.length) {
                Dom.enableRepeatButton();
                $('.flashcard-container').removeClass('introducing introducing-active').addClass('fade-out');
                LearnState.isIntroducingWord = false;

                const timeoutId = setTimeout(() => {
                    if (!State.abortAllOperations) {
                        State.transitionTo(State.STATES.QUIZ_READY, 'Introduction complete');
                        this.startRound();
                    }
                }, 300);
                State.addTimeout(timeoutId);
                return;
            }

            const currentWord = words[wordIndex];
            const $currentCard = $('.flashcard-container[data-word-index="' + wordIndex + '"]');

            if (!$currentCard.length) {
                console.warn('Card disappeared during introduction');
                return;
            }

            $('.flashcard-container').removeClass('introducing-active');
            $currentCard.addClass('introducing-active');

            const timeoutId = setTimeout(() => {
                if (State.abortAllOperations || !State.isIntroducing()) return;

                const audioPattern = JSON.parse($currentCard.attr('data-audio-pattern') || '[]');
                const audioUrl = audioPattern[repetition] || audioPattern[0];
                const managedAudio = root.FlashcardAudio.createIntroductionAudio(audioUrl);

                if (!managedAudio) {
                    console.error('Failed to create introduction audio');
                    return;
                }

                managedAudio.playUntilEnd()
                    .then(() => {
                        if (State.abortAllOperations || !managedAudio.isValid() || !State.isIntroducing()) {
                            managedAudio.cleanup();
                            return;
                        }

                        LearnState.wordIntroductionProgress[currentWord.id] =
                            (LearnState.wordIntroductionProgress[currentWord.id] || 0) + 1;

                        Dom.updateLearningProgress(
                            LearnState.introducedWordIDs.length,
                            LearnState.totalWordCount,
                            LearnState.wordCorrectCounts,
                            LearnState.wordIntroductionProgress
                        );

                        managedAudio.cleanup();

                        if (repetition < State.AUDIO_REPETITIONS - 1) {
                            $currentCard.removeClass('introducing-active');
                            const nextTimeoutId = setTimeout(() => {
                                if (!State.abortAllOperations) {
                                    this.playIntroductionSequence(words, wordIndex, repetition + 1);
                                }
                            }, INTRO_GAP_MS);
                            State.addTimeout(nextTimeoutId);
                        } else {
                            if (!LearnState.introducedWordIDs.includes(currentWord.id)) {
                                LearnState.introducedWordIDs.push(currentWord.id);
                            }
                            const nextTimeoutId = setTimeout(() => {
                                if (!State.abortAllOperations) {
                                    this.playIntroductionSequence(words, wordIndex + 1, 0);
                                }
                            }, INTRO_WORD_GAP_MS);
                            State.addTimeout(nextTimeoutId);
                        }
                    })
                    .catch(err => {
                        console.error('Audio play failed:', err);
                        managedAudio.cleanup();
                    });
            }, 100);
            State.addTimeout(timeoutId);
        }

        isComplete() {
            return this.getNextItem() === null && !State.isFirstRound;
        }

        getDisplayConfig() {
            return {
                showRepeatButton: true,
                showProgressBar: true,
                showCategoryDisplay: true,
                showModeSwitcher: true,
                interactionType: 'click'
            };
        }

        getResults() {
            return {
                correctOnFirstTry: LearnState.introducedWordIDs.length,
                incorrect: []
            };
        }

        getModeName() {
            return 'learn';
        }
    }

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.LearnMode = LearnMode;
})(window, jQuery);