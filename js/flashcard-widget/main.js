(function (root, $) {
    'use strict';
    const { Util, State, Dom, Effects, Selection, Cards, Results } = root.LLFlashcards;

    // init shared audio bits early
    root.FlashcardAudio.initializeAudio();
    root.FlashcardLoader.loadAudio(root.FlashcardAudio.getCorrectAudioURL());
    root.FlashcardLoader.loadAudio(root.FlashcardAudio.getWrongAudioURL());

    function updateModeSwitcherButton() {
        const $btn = $('#ll-tools-mode-switcher');
        if (!$btn.length) return;

        if (State.isLearningMode) {
            $btn.removeClass('learning-mode').addClass('standard-mode');
            $btn.find('.mode-icon').text('❓');
            $btn.attr('aria-label', 'Switch to Standard Mode');
            $btn.attr('title', 'Switch to Standard Mode');
        } else {
            $btn.removeClass('standard-mode').addClass('learning-mode');
            $btn.find('.mode-icon').text('🎓');
            $btn.attr('aria-label', 'Switch to Learning Mode');
            $btn.attr('title', 'Switch to Learning Mode');
        }
        $btn.show();
    }

    function switchMode(newMode) {
        // Debounce ultra-rapid clicks on the mode switcher
        const $btn = $('#ll-tools-mode-switcher');
        if ($btn.length) { $btn.prop('disabled', true); }

        // 1) HARD STOP all audio before touching DOM or State
        try {
            root.FlashcardAudio.resetAudioState(); // pauses all, clears onended, removes <audio>
        } catch (e) {
            // Fallback: at least pause what we can
            try { root.FlashcardAudio.pauseAllAudio(); } catch (e2) { }
        }

        // 2) Reset state and set the new mode
        const targetMode = newMode || (State.isLearningMode ? 'standard' : 'learning');
        State.reset();
        State.isLearningMode = (targetMode === 'learning');

        // 3) Hide any results screen that may be visible
        if (root.LLFlashcards && root.LLFlashcards.Results && typeof root.LLFlashcards.Results.hideResults === 'function') {
            root.LLFlashcards.Results.hideResults();
        }

        // 4) Clear the stage and rebuild header UI
        $('#ll-tools-flashcard').empty();
        Dom.restoreHeaderUI();
        updateModeSwitcherButton();

        // 5) Start fresh round in the new mode
        startQuizRound();

        if ($btn.length) { $btn.prop('disabled', false); }
    }


    function onCorrectAnswer(targetWord, $correctCard) {
        if (State.userClickedCorrectAnswer) return;
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
                State.categoryRepetitionQueues[State.currentCategoryName].push({
                    wordData: targetWord,
                    reappearRound: (State.categoryRoundCount[State.currentCategoryName] || 0) + Util.randomInt(1, 3),
                });
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
                startQuizRound();
            }, 600);
        });
    }

    function onWrongAnswer(targetWord, index, $wrong) {
        if (State.userClickedCorrectAnswer) return;

        if (State.isLearningMode) {
            if (root.LLFlashcards?.LearningMode) {
                root.LLFlashcards.LearningMode.recordAnswerResult(targetWord.id, false);
            }
        } else {
            State.categoryRepetitionQueues[State.currentCategoryName] = State.categoryRepetitionQueues[State.currentCategoryName] || [];
            State.categoryRepetitionQueues[State.currentCategoryName].push({
                wordData: targetWord,
                reappearRound: (State.categoryRoundCount[State.currentCategoryName] || 0) + Util.randomInt(1, 3),
            });
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
            const firstThree = State.categoryNames.slice(0, 3);
            root.FlashcardLoader.loadResourcesForCategory(firstThree[0], function () {
                root.FlashcardOptions.initializeOptionsCount(number_of_options);

                if (State.isLearningMode) {
                    Selection.initializeLearningMode();
                }

                updateModeSwitcherButton();
                runQuizRound();
            });
            for (let i = 1; i < firstThree.length; i++) root.FlashcardLoader.loadResourcesForCategory(firstThree[i]);
        } else {
            runQuizRound();
        }
    }

    function runQuizRound() {
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
                Results.showResults();
                return;
            }

            if (State.isIntroducingWord) {
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
                if (questionAudio) {
                    target.audio = questionAudio;
                }
            }

            root.FlashcardAudio.setTargetWordAudio(target);
            Dom.hideLoading();
        });
    }

    function showLoadingError() {
        const msgs = root.llToolsFlashcardsMessages || {};
        $('#quiz-results-title').text(msgs.loadingError || 'Loading Error');
        $('#quiz-results-message').html(
            msgs.noWordsFound ||
            'No words could be loaded for this quiz. Please check that:<br>' +
            '• The category exists and has words<br>' +
            '• Words are properly assigned to the category<br>' +
            '• If using wordsets, the wordset contains words for this category'
        ).show();
        $('#quiz-results').show();
        $('#correct-count').parent().hide();
        $('#restart-quiz').hide();
        $('#quiz-mode-buttons').hide();
        Dom.hideLoading();
        $('#ll-tools-repeat-flashcard').hide();
        $('#ll-tools-category-stack, #ll-tools-category-display').hide();
    }

    function handleWordIntroduction(words) {
        // Ensure words is an array
        const wordsArray = Array.isArray(words) ? words : [words];

        $('#ll-tools-flashcard').empty();
        Dom.restoreHeaderUI();

        // Disable the repeat button during introduction
        Dom.disableRepeatButton();

        // Restore progress bar display for learning mode
        if (State.isLearningMode) {
            Dom.updateLearningProgress(
                State.introducedWordIDs.length,
                State.totalWordCount,
                State.wordCorrectCounts,
                State.wordIntroductionProgress
            );
        }

        const mode = Selection.getCurrentDisplayMode();

        // Load all words
        Promise.all(wordsArray.map(word => {
            return root.FlashcardLoader.loadResourcesForWord(word, mode);
        })).then(function () {
            // Show all words being introduced
            wordsArray.forEach((word, index) => {
                const $card = Cards.appendWordToContainer(word);
                $card.attr('data-word-index', index);

                // Get both introduction and isolation audio if available
                const introAudio = root.FlashcardAudio.selectBestAudio(word, ['introduction']);
                const isoAudio = root.FlashcardAudio.selectBestAudio(word, ['isolation']);

                // Determine the audio pattern
                let audioPattern;
                if (introAudio && isoAudio && introAudio !== isoAudio) {
                    // Both types available - randomly choose one of two patterns
                    const useIntroFirst = Math.random() < 0.5;
                    if (useIntroFirst) {
                        audioPattern = [introAudio, isoAudio, introAudio]; // intro-iso-intro
                    } else {
                        audioPattern = [isoAudio, introAudio, isoAudio]; // iso-intro-iso
                    }
                } else {
                    // Only one type available - use it 3 times
                    const singleAudio = introAudio || isoAudio || word.audio;
                    audioPattern = [singleAudio, singleAudio, singleAudio];
                }

                // Store the pattern as JSON string
                $card.attr('data-audio-pattern', JSON.stringify(audioPattern));
            });

            // Make non-clickable during introduction
            $('.flashcard-container').addClass('introducing').css('pointer-events', 'none');

            Dom.hideLoading();
            $('.flashcard-container').fadeIn(600);

            // Wait for fade-in to complete, then start the audio sequence
            setTimeout(() => {
                playIntroductionSequence(wordsArray, 0, 0);
            }, 650);
        });
    }

    function playIntroductionSequence(words, wordIndex, repetition) {
        if (wordIndex >= words.length) {
            // All words have been introduced with all repetitions
            // Re-enable the repeat button
            Dom.enableRepeatButton();

            // Automatically proceed to quiz without waiting for click
            $('.flashcard-container').removeClass('introducing introducing-active')
                .addClass('fade-out');

            State.isIntroducingWord = false;

            setTimeout(function () {
                startQuizRound();
            }, 300);
            return;
        }

        const currentWord = words[wordIndex];
        const $currentCard = $('.flashcard-container[data-word-index="' + wordIndex + '"]');

        // Set up visual state BEFORE playing audio
        $('.flashcard-container').removeClass('introducing-active');
        $currentCard.addClass('introducing-active');

        // Small delay to ensure CSS updates, then play audio
        setTimeout(() => {
            // Get the audio pattern for this word
            const audioPattern = JSON.parse($currentCard.attr('data-audio-pattern') || '[]');
            const audioUrl = audioPattern[repetition] || audioPattern[0];

            const audio = new Audio(audioUrl);

            audio.play();
            audio.onended = function () {
                // Increment introduction progress after each repetition
                State.wordIntroductionProgress[currentWord.id] = (State.wordIntroductionProgress[currentWord.id] || 0) + 1;

                // Update progress bar after each repetition
                Dom.updateLearningProgress(
                    State.introducedWordIDs.length,
                    State.totalWordCount,
                    State.wordCorrectCounts,
                    State.wordIntroductionProgress
                );

                if (repetition < State.AUDIO_REPETITIONS - 1) {
                    // Play this word again with animation
                    $currentCard.removeClass('introducing-active');
                    setTimeout(() => {
                        playIntroductionSequence(words, wordIndex, repetition + 1);
                    }, 300);
                } else {
                    // This word's introduction is complete - mark it as introduced NOW
                    if (!State.introducedWordIDs.includes(currentWord.id)) {
                        State.introducedWordIDs.push(currentWord.id);
                    }

                    // Move to next word
                    setTimeout(() => {
                        playIntroductionSequence(words, wordIndex + 1, 0);
                    }, 600);
                }
            };
        }, 100);
    }

    function initFlashcardWidget(selectedCategories, mode) {
        if (mode === 'learning') {
            State.isLearningMode = true;
        }

        if (State.widgetActive) {
            return;
        }
        State.widgetActive = true;

        if (root.LLFlashcards && root.LLFlashcards.Results && typeof root.LLFlashcards.Results.hideResults === 'function') {
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
            const btn = $(this);
            const audio = root.FlashcardAudio.getCurrentTargetAudio();
            if (!audio) return;
            if (!audio.paused) {
                audio.pause(); audio.currentTime = 0; Dom.setRepeatButton('play');
            } else {
                audio.play().then(() => { Dom.setRepeatButton('stop'); }).catch(() => { });
                audio.onended = function () { Dom.setRepeatButton('play'); };
            }
        });

        // Mode switcher button handler
        $('#ll-tools-mode-switcher').off('click').on('click', function () {
            switchMode();
        });

        // Results mode buttons
        $('#restart-standard-mode').off('click').on('click', function () {
            switchMode('standard');
        });

        $('#restart-learning-mode').off('click').on('click', function () {
            switchMode('learning');
        });

        $('#restart-quiz').off('click').on('click', restartQuiz);

        Dom.showLoading();
        updateModeSwitcherButton();
        startQuizRound();
    }

    function closeFlashcard() {
        if (root.LLFlashcards && root.LLFlashcards.Results && typeof root.LLFlashcards.Results.hideResults === 'function') {
            root.LLFlashcards.Results.hideResults();
        }

        State.reset();
        State.categoryNames = [];
        $('#ll-tools-flashcard').empty();
        $('#ll-tools-flashcard-header').hide();
        $('#ll-tools-flashcard-quiz-popup').hide();
        $('#ll-tools-flashcard-popup').hide();
        $('#ll-tools-mode-switcher').hide();
        $('body').removeClass('ll-tools-flashcard-open');
    }

    function restartQuiz() {
        State.reset();
        root.LLFlashcards.Results.hideResults();
        $('#ll-tools-flashcard').empty();
        Dom.restoreHeaderUI();
        updateModeSwitcherButton();
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