(function (root, $) {
    'use strict';
    // Prevent double-loading this file (can happen with some minify/defer stacks)
    if (window.__LLFlashcardsMainLoaded) { return; }
    window.__LLFlashcardsMainLoaded = true;

    const { Util, State, Dom, Effects, Selection, Cards, Results } = root.LLFlashcards;

    // --- Learning-mode introduction pacing (new) ---
    const INTRO_GAP_MS = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData.introSilenceMs === 'number')
        ? root.llToolsFlashcardsData.introSilenceMs
        : 800;   // gap in ms between the 3 plays of the same word

    const INTRO_WORD_GAP_MS = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData.introWordSilenceMs === 'number')
        ? root.llToolsFlashcardsData.introWordSilenceMs
        : 800;  // gap in ms before moving to next word

    // Prevent double-trigger when user taps mode switch rapidly
    let MODE_SWITCHING = false;
    let MODE_LAST_SWITCH_TS = 0;
    const MODE_SWITCH_COOLDOWN_MS = 1500;

    // ---- Timer & Session guards (prevents ghost callbacks) ----
    let __LLTimers = new Set();
    let __LLSession = 0;

    function newSession() {
        __LLSession++;
        // cancel any outstanding timers
        __LLTimers.forEach(id => clearTimeout(id));
        __LLTimers.clear();
    }

    function setGuardedTimeout(fn, ms) {
        const sessionAtSchedule = __LLSession;
        const id = setTimeout(() => {
            __LLTimers.delete(id);
            if (sessionAtSchedule !== __LLSession) return; // stale callback: ignore
            try { fn(); } catch (e) { /* no-op */ }
        }, ms);
        __LLTimers.add(id);
        return id;
    }

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
        const now = Date.now();
        // Re-entrancy + cooldown guard
        if (MODE_SWITCHING || (now - MODE_LAST_SWITCH_TS) < MODE_SWITCH_COOLDOWN_MS) {
            return; // ignore rapid re-clicks
        }
        MODE_SWITCHING = true;

        const $btn = $('#ll-tools-mode-switcher');
        if ($btn.length) {
            $btn.prop('disabled', true).attr('aria-busy', 'true');
        }

        try {
            // HARD STOP all audio first
            try { root.FlashcardAudio.resetAudioState(); }
            catch (e) { try { root.FlashcardAudio.pauseAllAudio(); } catch (_) { } }

            newSession();
            // Flip mode atomically
            const targetMode = newMode || (State.isLearningMode ? 'standard' : 'learning');
            State.reset();
            State.isLearningMode = (targetMode === 'learning');

            // Hide any results that may be visible
            if (root.LLFlashcards && root.LLFlashcards.Results && typeof root.LLFlashcards.Results.hideResults === 'function') {
                root.LLFlashcards.Results.hideResults();
            }

            // Rebuild UI
            $('#ll-tools-flashcard').empty();
            Dom.restoreHeaderUI();
            updateModeSwitcherButton();

            // Fresh round
            startQuizRound();
        } finally {
            MODE_LAST_SWITCH_TS = Date.now();
            // Release the guard after a short cooldown
            setTimeout(function () {
                MODE_SWITCHING = false;
                if ($btn && $btn.length) {
                    $btn.prop('disabled', false).removeAttr('aria-busy');
                }
            }, MODE_SWITCH_COOLDOWN_MS);
        }
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
            // Only queue if there were wrong answers AND the word isn't already in the queue
            if (State.wrongIndexes.length > 0) {
                State.categoryRepetitionQueues[State.currentCategoryName] = State.categoryRepetitionQueues[State.currentCategoryName] || [];

                // Check if this word is already in the queue
                const alreadyQueued = State.categoryRepetitionQueues[State.currentCategoryName].some(
                    item => item.wordData.id === targetWord.id
                );

                // Only add if not already queued
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

            // Check if this word is already in the queue
            const alreadyQueued = State.categoryRepetitionQueues[State.currentCategoryName].some(
                item => item.wordData.id === targetWord.id
            );

            // Only add if not already queued
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
    }

    function handleWordIntroduction(words) {
        const wordsArray = Array.isArray(words) ? words : [words];

        $('#ll-tools-flashcard').empty();
        Dom.restoreHeaderUI();

        // Disable the repeat button during introduction
        Dom.disableRepeatButton();

        // Mark that we are in an intro sequence now (prevents parallel starts)
        State.isIntroducingWord = true;

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
            setGuardedTimeout(() => {
                playIntroductionSequence(wordsArray, 0, 0);
            }, 650);
        });
    }

    function playIntroductionSequence(words, wordIndex, repetition) {
        if (wordIndex >= words.length) {
            Dom.enableRepeatButton();
            $('.flashcard-container').removeClass('introducing introducing-active').addClass('fade-out');
            State.isIntroducingWord = false;
            setGuardedTimeout(function () {
                startQuizRound();
            }, 300);
            return;
        }

        const currentWord = words[wordIndex];
        const $currentCard = $('.flashcard-container[data-word-index="' + wordIndex + '"]');

        $('.flashcard-container').removeClass('introducing-active');
        $currentCard.addClass('introducing-active');

        setGuardedTimeout(() => {
            const audioPattern = JSON.parse($currentCard.attr('data-audio-pattern') || '[]');
            const audioUrl = audioPattern[repetition] || audioPattern[0];
            const audio = new Audio(audioUrl);

            const WATCHDOG_MAX_MS = 15000;
            const watchdogId = setGuardedTimeout(() => {
                try { audio.pause(); } catch (e) { }
                handleEnd();
            }, WATCHDOG_MAX_MS);

            audio.onended = handleEnd;
            audio.onerror = handleEnd;

            try { audio.play(); } catch (e) { handleEnd(); }

            function handleEnd() {
                try {
                    audio.pause();
                    audio.onended = null;
                    audio.onerror = null;
                    audio.ontimeupdate = null;
                    audio.onloadstart = null;
                    audio.src = '';
                } catch (e) { }

                // We scheduled watchdog with guarded helper; nothing more to clear here.

                // Increment introduction progress after each repetition
                State.wordIntroductionProgress[currentWord.id] = (State.wordIntroductionProgress[currentWord.id] || 0) + 1;

                // Update progress bar
                Dom.updateLearningProgress(
                    State.introducedWordIDs.length,
                    State.totalWordCount,
                    State.wordCorrectCounts,
                    State.wordIntroductionProgress
                );

                if (repetition < State.AUDIO_REPETITIONS - 1) {
                    // Next repetition of the SAME word — use the CORRECT constant name
                    $('.flashcard-container').removeClass('introducing-active');
                    setGuardedTimeout(() => {
                        playIntroductionSequence(words, wordIndex, repetition + 1);
                    }, INTRO_WORD_GAP_MS); // <<< was INTRO_GAP_MS (undefined)
                } else {
                    if (!State.introducedWordIDs.includes(currentWord.id)) {
                        State.introducedWordIDs.push(currentWord.id);
                    }
                    // Move to next word after the gap
                    setGuardedTimeout(() => {
                        playIntroductionSequence(words, wordIndex + 1, 0);
                    }, INTRO_WORD_GAP_MS);
                }
            }
        }, 0);
    }

    function initFlashcardWidget(selectedCategories, mode) {
        newSession();
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
        // CRITICAL: Stop and clean up ALL audio first to prevent rogue playback
        try {
            root.FlashcardAudio.resetAudioState();
        } catch (e) {
            console.error('Error resetting audio state:', e);
        }
        newSession();

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
        newSession();
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