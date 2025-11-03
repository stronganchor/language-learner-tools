(function (root, $) {
    'use strict';
    // Prevent double-loading this file
    if (window.__LLFlashcardsMainLoaded) { return; }
    window.__LLFlashcardsMainLoaded = true;

    const { Util, State, Dom, Effects, Selection, Cards, Results, StateMachine } = root.LLFlashcards;
    const { STATES } = State;

    // Learning-mode introduction pacing
    const INTRO_GAP_MS = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData.introSilenceMs === 'number')
        ? root.llToolsFlashcardsData.introSilenceMs : 800;
    const INTRO_WORD_GAP_MS = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData.introWordSilenceMs === 'number')
        ? root.llToolsFlashcardsData.introWordSilenceMs : 800;

    // Timer & Session guards
    let __LLTimers = new Set();
    let __LLSession = 0;
    let closingCleanupPromise = null;

    function newSession() {
        __LLSession++;
        __LLTimers.forEach(id => clearTimeout(id));
        __LLTimers.clear();

        // IMPORTANT: pause the *previous* audio session (snapshot the id now)
        try {
            var sid = (window.FlashcardAudio && typeof window.FlashcardAudio.getCurrentSessionId === 'function')
                ? window.FlashcardAudio.getCurrentSessionId()
                : undefined;
            window.FlashcardAudio.pauseAllAudio(sid);
        } catch (_) { /* no-op */ }
    }


    function setGuardedTimeout(fn, ms) {
        const sessionAtSchedule = __LLSession;
        const id = setTimeout(() => {
            __LLTimers.delete(id);
            if (sessionAtSchedule !== __LLSession) return;
            try { fn(); } catch (e) { console.error('Timeout error:', e); }
        }, ms);
        __LLTimers.add(id);
        return id;
    }

    // Init audio
    root.FlashcardAudio.initializeAudio();
    root.FlashcardLoader.loadAudio(root.FlashcardAudio.getCorrectAudioURL());
    root.FlashcardLoader.loadAudio(root.FlashcardAudio.getWrongAudioURL());

    function updateModeSwitcherButton() {
        const $btn = $('#ll-tools-mode-switcher');
        if (!$btn.length) return;

        if (State.isLearningMode) {
            $btn.removeClass('learning-mode').addClass('practice-mode');
            $btn.find('.mode-icon').text('❓');
            $btn.attr('aria-label', 'Switch to Practice Mode');
            $btn.attr('title', 'Switch to Practice Mode');
        } else {
            $btn.removeClass('practice-mode').addClass('learning-mode');
            $btn.find('.mode-icon').text('🎓');
            $btn.attr('aria-label', 'Switch to Learning Mode');
            $btn.attr('title', 'Switch to Learning Mode');
        }
        $btn.show();
    }

    function switchMode(newMode) {
        if (!State.canSwitchMode()) {
            console.warn('Cannot switch mode in state:', State.getState());
            return;
        }

        const $btn = $('#ll-tools-mode-switcher');
        if ($btn.length) $btn.prop('disabled', true).attr('aria-busy', 'true');

        State.transitionTo(STATES.SWITCHING_MODE, 'User requested mode switch');

        // Stop anything in-flight right now.
        State.abortAllOperations = true;
        State.clearActiveTimeouts();
        $('#ll-tools-learning-progress').hide().empty();

        root.FlashcardAudio.startNewSession().then(function () {
            const targetMode = newMode || (State.isLearningMode ? 'practice' : 'learning');

            // Full reset for a clean session
            State.reset();

            // IMPORTANT: allow operations again before we start the next round
            State.abortAllOperations = false;

            State.isLearningMode = (targetMode === 'learning');

            if (root.LLFlashcards?.Results?.hideResults) {
                root.LLFlashcards.Results.hideResults();
            }

            $('#ll-tools-flashcard').empty();
            Dom.restoreHeaderUI();
            updateModeSwitcherButton();

            // Kick off fresh load
            State.transitionTo(STATES.LOADING, 'Mode switch complete, reloading');
            startQuizRound();

            setTimeout(function () {
                if ($btn.length) {
                    $btn.prop('disabled', false).removeAttr('aria-busy');
                }
            }, 1500);
        }).catch(function (err) {
            console.error('Error during mode switch:', err);
            State.forceTransitionTo(STATES.IDLE, 'Mode switch error');
            if ($btn.length) $btn.prop('disabled', false).removeAttr('aria-busy');
        });
    }

    function onCorrectAnswer(targetWord, $correctCard) {
        if (!State.canProcessAnswer()) {
            console.warn('Cannot process answer in state:', State.getState());
            return;
        }
        if (State.userClickedCorrectAnswer) return;

        State.transitionTo(STATES.PROCESSING_ANSWER, 'Correct answer');
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

        if (State.isLearningMode) {
            if (root.LLFlashcards?.LearningMode) {
                root.LLFlashcards.LearningMode.recordAnswerResult(targetWord.id, true, State.hadWrongAnswerThisTurn);
            }
            State.isIntroducingWord = false;
        } else {
            if (State.wrongIndexes.length > 0) {
                State.categoryRepetitionQueues[State.currentCategoryName] = State.categoryRepetitionQueues[State.currentCategoryName] || [];
                const alreadyQueued = State.categoryRepetitionQueues[State.currentCategoryName].some(
                    item => item.wordData.id === targetWord.id
                );
                if (!alreadyQueued) {
                    State.categoryRepetitionQueues[State.currentCategoryName].push({
                        wordData: targetWord,
                        reappearRound: (State.categoryRoundCount[State.currentCategoryName] || 0) + Util.randomInt(1, 3),
                    });
                }
            }
        }

        root.FlashcardAudio.playFeedback(true, null, function () {
            if (!State.quizResults.incorrect.includes(targetWord.id)) {
                State.quizResults.correctOnFirstTry += 1;
            }
            $('.flashcard-container').not($correctCard).addClass('fade-out');
            setTimeout(function () {
                State.isFirstRound = false;
                State.userClickedCorrectAnswer = false;
                State.transitionTo(STATES.QUIZ_READY, 'Ready for next question');
                startQuizRound();
            }, 600);
        });
    }

    function onWrongAnswer(targetWord, index, $wrong) {
        if (!State.canProcessAnswer()) {
            console.warn('Cannot process answer in state:', State.getState());
            return;
        }
        if (State.userClickedCorrectAnswer) return;

        if (State.isLearningMode) {
            if (root.LLFlashcards?.LearningMode) {
                root.LLFlashcards.LearningMode.recordAnswerResult(targetWord.id, false);
            }
        } else {
            State.categoryRepetitionQueues[State.currentCategoryName] = State.categoryRepetitionQueues[State.currentCategoryName] || [];
            const alreadyQueued = State.categoryRepetitionQueues[State.currentCategoryName].some(
                item => item.wordData.id === targetWord.id
            );
            if (!alreadyQueued) {
                State.categoryRepetitionQueues[State.currentCategoryName].push({
                    wordData: targetWord,
                    reappearRound: (State.categoryRoundCount[State.currentCategoryName] || 0) + Util.randomInt(1, 3),
                });
            }
        }

        State.hadWrongAnswerThisTurn = true;
        root.FlashcardAudio.playFeedback(false, targetWord.audio, null);
        $wrong.addClass('fade-out').one('transitionend', function () { $wrong.remove(); });

        if (!State.quizResults.incorrect.includes(targetWord.id)) State.quizResults.incorrect.push(targetWord.id);
        State.wrongIndexes.push(index);

        const mode = Selection.getCurrentDisplayMode();
        if (State.wrongIndexes.length === 2) {
            $('.flashcard-container').not(function () {
                return (mode === 'image') ?
                    ($(this).find('img').attr('src') === targetWord.image) :
                    ($(this).find('.quiz-text').text() === (targetWord.label || ''));
            }).remove();
        }
    }

    function startQuizRound(number_of_options) {
        if (State.isFirstRound) {
            if (!State.is(STATES.LOADING)) {
                State.transitionTo(STATES.LOADING, 'First round initialization');
            }

            const firstThree = State.categoryNames.slice(0, 3);
            root.FlashcardLoader.loadResourcesForCategory(firstThree[0], function () {
                root.FlashcardOptions.initializeOptionsCount(number_of_options);

                if (State.isLearningMode) {
                    Selection.initializeLearningMode();
                }

                updateModeSwitcherButton();
                State.transitionTo(STATES.QUIZ_READY, 'Resources loaded');
                runQuizRound();
            });

            for (let i = 1; i < firstThree.length; i++) {
                root.FlashcardLoader.loadResourcesForCategory(firstThree[i]);
            }
        } else {
            runQuizRound();
        }
    }

    function runQuizRound() {
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

        let target;
        if (State.isLearningMode) {
            target = Selection.selectLearningModeWord();

            Dom.updateLearningProgress(
                State.introducedWordIDs.length,
                State.totalWordCount,
                State.wordCorrectCounts,
                State.wordIntroductionProgress
            );

            if (!target) {
                if (State.isFirstRound && State.totalWordCount === 0) {
                    showLoadingError();
                    return;
                }
                State.transitionTo(STATES.SHOWING_RESULTS, 'Quiz complete');
                Results.showResults();
                return;
            }

            if (State.isIntroducingWord) {
                if (!State.canIntroduceWords()) {
                    console.warn('Cannot introduce words in state:', State.getState());
                    return;
                }
                State.transitionTo(STATES.INTRODUCING_WORDS, 'Starting word introduction');
                handleWordIntroduction(target);
                return;
            }
        } else {
            root.FlashcardOptions.calculateNumberOfOptions(State.wrongIndexes, State.isFirstRound, State.currentCategoryName);
            target = Selection.selectTargetWordAndCategory();

            if (!target) {
                if (State.isFirstRound) {
                    let hasWords = false;
                    for (let catName of State.categoryNames) {
                        if (State.wordsByCategory[catName] && State.wordsByCategory[catName].length > 0) {
                            hasWords = true;
                            break;
                        }
                    }
                    if (!hasWords) {
                        showLoadingError();
                        return;
                    }
                }
                State.transitionTo(STATES.SHOWING_RESULTS, 'Quiz complete');
                Results.showResults();
                return;
            }
        }

        root.FlashcardLoader.loadResourcesForWord(target, Selection.getCurrentDisplayMode()).then(function () {
            if (State.isLearningMode && root.LLFlashcards?.LearningMode) {
                const choiceCount = root.LLFlashcards.LearningMode.getChoiceCount();
                State.currentChoiceCount = choiceCount;
                if (root.FlashcardOptions?.initializeOptionsCount) {
                    root.FlashcardOptions.initializeOptionsCount(choiceCount);
                }
            }
            Selection.fillQuizOptions(target);

            if (State.isLearningMode && !State.isIntroducingWord) {
                const questionAudio = root.FlashcardAudio.selectBestAudio(target, ['question', 'isolation', 'introduction']);
                if (questionAudio) target.audio = questionAudio;
            }

            root.FlashcardAudio.setTargetWordAudio(target);
            Dom.hideLoading();
            Dom.enableRepeatButton();
            State.transitionTo(STATES.SHOWING_QUESTION, 'Question displayed');
        }).catch(function (err) {
            console.error('Error in runQuizRound:', err);
            State.forceTransitionTo(STATES.QUIZ_READY, 'Error recovery');
        });
    }

    function showLoadingError() {
        const msgs = root.llToolsFlashcardsMessages || {};
        $('#quiz-results-title').text(msgs.loadingError || 'Loading Error');

        const errorBullets = [
            msgs.checkCategoryExists || 'The category exists and has words',
            msgs.checkWordsAssigned || 'Words are properly assigned to the category',
            msgs.checkWordsetFilter || 'If using wordsets, the wordset contains words for this category'
        ];

        const errorMessage = (msgs.noWordsFound || 'No words could be loaded for this quiz. Please check that:') +
            '<br>• ' + errorBullets.join('<br>• ');

        $('#quiz-results-message').html(errorMessage).show();
        $('#quiz-results').show();
        $('#correct-count').parent().hide();
        $('#restart-quiz').hide();
        $('#quiz-mode-buttons').hide();
        Dom.hideLoading();
        $('#ll-tools-repeat-flashcard').hide();
        $('#ll-tools-category-stack, #ll-tools-category-display').hide();
        State.forceTransitionTo(STATES.SHOWING_RESULTS, 'Error state');
    }

    function handleWordIntroduction(words) {
        if (!State.isIntroducing()) {
            console.warn('handleWordIntroduction called but not in INTRODUCING_WORDS state');
            return;
        }

        const wordsArray = Array.isArray(words) ? words : [words];

        $('#ll-tools-flashcard').empty();
        Dom.restoreHeaderUI();
        Dom.disableRepeatButton();

        if (State.isLearningMode) {
            Dom.updateLearningProgress(
                State.introducedWordIDs.length,
                State.totalWordCount,
                State.wordCorrectCounts,
                State.wordIntroductionProgress
            );
        }

        const mode = Selection.getCurrentDisplayMode();

        Promise.all(wordsArray.map(word => {
            return root.FlashcardLoader.loadResourcesForWord(word, mode);
        })).then(function () {
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
                    audioPattern = useIntroFirst ? [introAudio, isoAudio, introAudio] : [isoAudio, introAudio, isoAudio];
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
                playIntroductionSequence(wordsArray, 0, 0);
            }, 650);
            State.addTimeout(timeoutId);
        });
    }

    function playIntroductionSequence(words, wordIndex, repetition) {
        if (State.abortAllOperations || !State.isIntroducing()) {
            console.log('Introduction sequence aborted');
            return;
        }

        if (wordIndex >= words.length) {
            Dom.enableRepeatButton();
            $('.flashcard-container').removeClass('introducing introducing-active').addClass('fade-out');
            State.isIntroducingWord = false;

            const timeoutId = setTimeout(function () {
                if (!State.abortAllOperations) {
                    State.transitionTo(STATES.QUIZ_READY, 'Introduction complete');
                    startQuizRound();
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

                    State.wordIntroductionProgress[currentWord.id] =
                        (State.wordIntroductionProgress[currentWord.id] || 0) + 1;

                    Dom.updateLearningProgress(
                        State.introducedWordIDs.length,
                        State.totalWordCount,
                        State.wordCorrectCounts,
                        State.wordIntroductionProgress
                    );

                    managedAudio.cleanup();

                    if (repetition < State.AUDIO_REPETITIONS - 1) {
                        $currentCard.removeClass('introducing-active');
                        const nextTimeoutId = setTimeout(() => {
                            if (!State.abortAllOperations) {
                                playIntroductionSequence(words, wordIndex, repetition + 1);
                            }
                        }, INTRO_GAP_MS);
                        State.addTimeout(nextTimeoutId);
                    } else {
                        if (!State.introducedWordIDs.includes(currentWord.id)) {
                            State.introducedWordIDs.push(currentWord.id);
                        }
                        const nextTimeoutId = setTimeout(() => {
                            if (!State.abortAllOperations) {
                                playIntroductionSequence(words, wordIndex + 1, 0);
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

    function initFlashcardWidget(selectedCategories, mode) {
        const proceed = () => {
            newSession();

            // Clear any leftover overlays/flags from a previous popup session
            try {
                if (root.LLFlashcards?.Dom?.hideAutoplayBlockedOverlay) {
                    root.LLFlashcards.Dom.hideAutoplayBlockedOverlay();
                }
                if (root.FlashcardAudio?.clearAutoplayBlock) {
                    root.FlashcardAudio.clearAutoplayBlock();
                }
            } catch (_) { }
            $('#ll-tools-flashcard').css('pointer-events', 'auto');

            $('#ll-tools-learning-progress').hide().empty();

            if (State.is(STATES.CLOSING)) {
                State.forceTransitionTo(STATES.IDLE, 'Reopening during close');
            } else if (!State.canTransitionTo(STATES.LOADING)) {
                State.forceTransitionTo(STATES.IDLE, 'Resetting before initialization');
            }

            State.transitionTo(STATES.LOADING, 'Widget initialization');

            return root.FlashcardAudio.startNewSession().then(function () {
                if (mode === 'learning') {
                    State.isLearningMode = true;
                }

                if (State.widgetActive) return;
                State.widgetActive = true;

                if (root.LLFlashcards?.Results?.hideResults) {
                    root.LLFlashcards.Results.hideResults();
                }

                State.categoryNames = Util.randomlySort(selectedCategories || []);
                root.categoryNames = State.categoryNames;
                State.firstCategoryName = State.categoryNames[0] || State.firstCategoryName;
                root.FlashcardLoader.loadResourcesForCategory(State.firstCategoryName);
                Dom.updateCategoryNameDisplay(State.firstCategoryName);

                $('body').addClass('ll-tools-flashcard-open');
                $('#ll-tools-close-flashcard').off('click').on('click', closeFlashcard);
                $('#ll-tools-flashcard-header').show();

                $('#ll-tools-repeat-flashcard').off('click').on('click', function () {
                    const audio = root.FlashcardAudio.getCurrentTargetAudio();
                    if (!audio) return;
                    if (!audio.paused) {
                        audio.pause(); audio.currentTime = 0; Dom.setRepeatButton('play');
                    } else {
                        audio.play().then(() => { Dom.setRepeatButton('stop'); }).catch(() => { });
                        audio.onended = function () { Dom.setRepeatButton('play'); };
                    }
                });

                $('#ll-tools-mode-switcher').off('click').on('click', () => switchMode());
                $('#restart-practice-mode').off('click').on('click', () => switchMode('practice'));
                $('#restart-learning-mode').off('click').on('click', () => switchMode('learning'));
                $('#restart-quiz').off('click').on('click', restartQuiz);

                Dom.showLoading();
                updateModeSwitcherButton();
                startQuizRound();

                // One-time "kick" to start audio on first user gesture if autoplay was blocked
                $('#ll-tools-flashcard-content')
                    .off('.llAutoplayKick')
                    .on('pointerdown.llAutoplayKick keydown.llAutoplayKick', function () {
                        try {
                            const a = root.FlashcardAudio && root.FlashcardAudio.getCurrentTargetAudio
                                ? root.FlashcardAudio.getCurrentTargetAudio()
                                : null;
                            if (a && a.paused) {
                                a.play().finally(() => {
                                    $('#ll-tools-flashcard-content').off('.llAutoplayKick');
                                });
                            } else {
                                $('#ll-tools-flashcard-content').off('.llAutoplayKick');
                            }
                        } catch (_) {
                            $('#ll-tools-flashcard-content').off('.llAutoplayKick');
                        }
                    });
            }).catch(function (err) {
                console.error('Failed to start audio session:', err);
                State.forceTransitionTo(STATES.IDLE, 'Initialization error');
            });
        };

        if (closingCleanupPromise) {
            return closingCleanupPromise
                .catch(err => {
                    console.warn('Waiting for previous flashcard cleanup before reopening', err);
                })
                .then(proceed);
        }

        return proceed();
    }

    function closeFlashcard() {
        if (closingCleanupPromise) {
            return closingCleanupPromise;
        }

        if (!State.is(STATES.CLOSING)) {
            State.transitionTo(STATES.CLOSING, 'User closed widget');
        }

        State.abortAllOperations = true;
        State.clearActiveTimeouts();
        newSession();

        // Clean up any overlay/gesture hooks immediately
        try {
            if (root.LLFlashcards?.Dom?.hideAutoplayBlockedOverlay) {
                root.LLFlashcards.Dom.hideAutoplayBlockedOverlay();
            }
            if (root.FlashcardAudio?.clearAutoplayBlock) {
                root.FlashcardAudio.clearAutoplayBlock();
            }
        } catch (_) { }
        $('#ll-tools-flashcard-content').off('.llAutoplayKick');
        $('#ll-tools-flashcard').css('pointer-events', 'auto');

        const cleanupPromise = root.FlashcardAudio.startNewSession()
            .catch(function (err) {
                console.error('Failed to start audio cleanup session:', err);
            })
            .then(function () {
                if (root.LLFlashcards?.Results?.hideResults) {
                    root.LLFlashcards.Results.hideResults();
                }

                State.reset();
                State.categoryNames = [];
                $('#ll-tools-flashcard').empty();
                $('#ll-tools-flashcard-header').hide();
                $('#ll-tools-flashcard-quiz-popup').hide();
                $('#ll-tools-flashcard-popup').hide();
                $('#ll-tools-mode-switcher').hide();
                $('#ll-tools-learning-progress').hide().empty();
                $('body').removeClass('ll-tools-flashcard-open');

                State.transitionTo(STATES.IDLE, 'Cleanup complete');
            })
            .catch(function (err) {
                console.error('Flashcard cleanup encountered an error:', err);
                State.forceTransitionTo(STATES.IDLE, 'Cleanup error');
            });

        closingCleanupPromise = cleanupPromise.finally(function () {
            closingCleanupPromise = null;
        });

        return closingCleanupPromise;
    }

    function restartQuiz() {
        newSession();
        $('#ll-tools-learning-progress').hide().empty();
        State.reset();
        root.LLFlashcards.Results.hideResults();
        $('#ll-tools-flashcard').empty();
        Dom.restoreHeaderUI();
        updateModeSwitcherButton();
        State.transitionTo(STATES.QUIZ_READY, 'Quiz restarted');
        startQuizRound();
    }

    if (root.llToolsFlashcardsData && root.llToolsFlashcardsData.categoriesPreselected) {
        root.FlashcardLoader.processFetchedWordData(root.llToolsFlashcardsData.firstCategoryData, State.firstCategoryName);
        root.FlashcardLoader.preloadCategoryResources(State.firstCategoryName);
    }

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Main = { initFlashcardWidget, startQuizRound, runQuizRound, onCorrectAnswer, onWrongAnswer, closeFlashcard, restartQuiz, switchMode };
    root.initFlashcardWidget = initFlashcardWidget;
})(window, jQuery);