(function (root, $) {
    'use strict';
    const { Util, State, Dom, Effects, Selection, Cards, Results } = root.LLFlashcards;

    // init shared audio bits early
    root.FlashcardAudio.initializeAudio();
    root.FlashcardLoader.loadAudio(root.FlashcardAudio.getCorrectAudioURL());
    root.FlashcardLoader.loadAudio(root.FlashcardAudio.getWrongAudioURL());

    function onCorrectAnswer(targetWord, $correctCard) {
        if (State.userClickedCorrectAnswer) return;
        $correctCard.addClass('correct-answer');

        const rect = $correctCard[0].getBoundingClientRect();
        Effects.startConfetti({ particleCount: 20, angle: 90, spread: 60, origin: { x: (rect.left + rect.width / 2) / window.innerWidth, y: (rect.top + rect.height / 2) / window.innerHeight }, duration: 50 });

        State.userClickedCorrectAnswer = true;

        // Learning mode: track correct count
        if (State.isLearningMode) {
            State.wordCorrectCounts[targetWord.id] = (State.wordCorrectCounts[targetWord.id] || 0) + 1;
            State.isIntroducingWord = false;
        }

        root.FlashcardAudio.playFeedback(true, null, function () {
            if (State.wrongIndexes.length > 0) {
                State.categoryRepetitionQueues[State.currentCategoryName] = State.categoryRepetitionQueues[State.currentCategoryName] || [];
                State.categoryRepetitionQueues[State.currentCategoryName].push({
                    wordData: targetWord,
                    reappearRound: (State.categoryRoundCount[State.currentCategoryName] || 0) + Util.randomInt(1, 3),
                });
            }
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

                // Initialize learning mode if enabled
                if (State.isLearningMode) {
                    Selection.initializeLearningMode();
                }

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

        console.log('runQuizRound - isLearningMode:', State.isLearningMode);

        // Learning mode uses different selection logic
        let target;
        if (State.isLearningMode) {
            console.log('Using learning mode selection');
            target = Selection.selectLearningModeWord();
            console.log('Selected target in learning mode:', target);

            // Update progress display
            const totalWords = State.introducedWordIDs.length + State.wordsToIntroduce.length;
            Dom.updateLearningProgress(
                State.introducedWordIDs.length,
                totalWords,
                State.wordCorrectCounts
            );

            // Check if quiz is complete
            if (!target) {
                Results.showResults();
                return;
            }

            // Handle introduction phase
            if (State.isIntroducingWord) {
                handleWordIntroduction(target);
                return;
            }
        } else {
            // Regular mode
            root.FlashcardOptions.calculateNumberOfOptions(State.wrongIndexes, State.isFirstRound, State.currentCategoryName);
            target = Selection.selectTargetWordAndCategory();

            if (!target) {
                Results.showResults();
                return;
            }
        }

        root.FlashcardLoader.loadResourcesForWord(target, Selection.getCurrentDisplayMode()).then(function () {
            Selection.fillQuizOptions(target);

            // Learning mode: use question audio if available, otherwise primary
            if (State.isLearningMode && !State.isIntroducingWord) {
                const questionAudio = root.FlashcardAudio.selectBestAudio(target, ['question', 'isolation']);
                if (questionAudio) {
                    target.audio = questionAudio;
                }
            }

            root.FlashcardAudio.setTargetWordAudio(target);
            Dom.hideLoading();
        });
    }

    function handleWordIntroduction(words) {
        // Ensure words is an array
        const wordsArray = Array.isArray(words) ? words : [words];

        $('#ll-tools-flashcard').empty();
        Dom.restoreHeaderUI();

        const mode = Selection.getCurrentDisplayMode();

        // Load all words
        Promise.all(wordsArray.map(word => {
            const introAudio = root.FlashcardAudio.selectBestAudio(word, ['introduction', 'isolation']);
            if (introAudio) {
                word.audio = introAudio;
            }
            return root.FlashcardLoader.loadResourcesForWord(word, mode);
        })).then(function () {
            // Show all words being introduced
            wordsArray.forEach((word, index) => {
                const $card = Cards.appendWordToContainer(word);
                // Store word index AND audio URL as data attributes IMMEDIATELY
                $card.attr('data-word-index', index);
                $card.attr('data-word-audio', word.audio);
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
            // Remove all introduction classes
            $('.flashcard-container').removeClass('introducing introducing-active introducing-waiting')
                .css('pointer-events', 'auto');

            // Add click handler to proceed to quiz
            $('.flashcard-container').off('click').on('click', function () {
                State.isIntroducingWord = false;
                $('.flashcard-container').addClass('fade-out');
                setTimeout(function () {
                    startQuizRound();
                }, 300);
            });
            return;
        }

        const currentWord = words[wordIndex];
        const $currentCard = $('.flashcard-container[data-word-index="' + wordIndex + '"]');

        // Set up visual state BEFORE playing audio
        $('.flashcard-container').removeClass('introducing-active');
        $('.flashcard-container').not($currentCard).addClass('introducing-waiting');
        $currentCard.removeClass('introducing-waiting').addClass('introducing-active');

        // Small delay to ensure CSS updates, then play audio
        setTimeout(() => {
            // Get audio URL from the card's data attribute (more reliable)
            const audioUrl = $currentCard.attr('data-word-audio');
            const audio = new Audio(audioUrl);

            console.log('Playing audio for word', wordIndex, ':', audioUrl); // Debug

            audio.play();
            audio.onended = function () {
                if (repetition < State.AUDIO_REPETITIONS - 1) {
                    // Play this word again with animation
                    $currentCard.removeClass('introducing-active');
                    setTimeout(() => {
                        playIntroductionSequence(words, wordIndex, repetition + 1);
                    }, 300);
                } else {
                    // Move to next word
                    setTimeout(() => {
                        playIntroductionSequence(words, wordIndex + 1, 0);
                    }, 600);
                }
            };
        }, 100);
    }

    function initFlashcardWidget(selectedCategories, mode) {
        console.log('initFlashcardWidget called with mode:', mode);

        // Set learning mode if specified
        if (mode === 'learning') {
            State.isLearningMode = true;
            console.log('Learning mode activated! State.isLearningMode:', State.isLearningMode);
        }

        // Guard: bail out if one instance is already active
        if (State.widgetActive) {
            return;
        }
        State.widgetActive = true;

        // Always start from a clean slate: hide any stale results UI
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

        $('#restart-quiz').off('click').on('click', restartQuiz);

        Dom.showLoading();
        startQuizRound();
    }

    function closeFlashcard() {
        // Clear any visible results first
        if (root.LLFlashcards && root.LLFlashcards.Results && typeof root.LLFlashcards.Results.hideResults === 'function') {
            root.LLFlashcards.Results.hideResults();
        }

        State.reset();
        State.categoryNames = [];
        $('#ll-tools-flashcard').empty();
        $('#ll-tools-flashcard-header').hide();
        $('#ll-tools-flashcard-quiz-popup').hide();
        $('#ll-tools-flashcard-popup').hide();
        $('body').removeClass('ll-tools-flashcard-open');
    }

    // Replace the entire function
    function restartQuiz() {
        State.reset();
        // make sure the results overlay is hidden before starting again
        root.LLFlashcards.Results.hideResults();
        $('#ll-tools-flashcard').empty();
        Dom.restoreHeaderUI();
        startQuizRound();
    }

    // Preload first category when preselected
    if (root.llToolsFlashcardsData && root.llToolsFlashcardsData.categoriesPreselected) {
        root.FlashcardLoader.processFetchedWordData(root.llToolsFlashcardsData.firstCategoryData, State.firstCategoryName);
        root.FlashcardLoader.preloadCategoryResources(State.firstCategoryName);
    }

    // exports
    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Main = { initFlashcardWidget, startQuizRound, runQuizRound, onCorrectAnswer, onWrongAnswer, closeFlashcard, restartQuiz };

    // legacy globals (if anything else calls these)
    root.initFlashcardWidget = initFlashcardWidget;
})(window, jQuery);
