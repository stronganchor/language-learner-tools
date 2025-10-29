(function (root, $) {
    'use strict';

    const State = root.LLFlashcards.State;
    const Dom = root.LLFlashcards.Dom;
    const Util = root.LLFlashcards.Util;

    const STATES = State.STATES;
    let currentMode = null;

    function newSession() {
        root.__llFlashcardSessionId = (root.__llFlashcardSessionId || 0) + 1;
        console.log('New flashcard session:', root.__llFlashcardSessionId);
    }

    function updateModeSwitcherButton() {
        const $btn = $('#ll-tools-mode-switcher');
        if (!$btn.length) return;

        const modeName = currentMode ? currentMode.getModeName() : 'quiz';
        const isLearning = modeName === 'learn';

        const icon = isLearning
            ? '<span style="font-size: 1.2em;">📝</span>'
            : '<span style="font-size: 1.2em;">🎓</span>';
        const text = isLearning ? 'Switch to Quiz' : 'Switch to Learn';

        $btn.html(icon + ' ' + text).show();
    }

    async function switchMode(newModeName) {
        if (!State.canSwitchMode()) {
            console.warn('Cannot switch mode in state:', State.getState());
            return;
        }

        const $btn = $('#ll-tools-mode-switcher');
        if ($btn.length) $btn.prop('disabled', true).attr('aria-busy', 'true');

        State.transitionTo(STATES.SWITCHING_MODE, 'User requested mode switch');

        State.abortAllOperations = true;
        State.clearActiveTimeouts();
        $('#ll-tools-learning-progress').hide().empty();

        try {
            await root.FlashcardAudio.startNewSession();

            if (currentMode) {
                await currentMode.cleanup();
            }

            const targetModeName = newModeName || (currentMode?.getModeName() === 'learn' ? 'quiz' : 'learn');

            State.reset();
            State.isLearningMode = (targetModeName === 'learn');

            if (root.LLFlashcards?.Results?.hideResults) {
                root.LLFlashcards.Results.hideResults();
            }

            $('#ll-tools-flashcard').empty();
            Dom.restoreHeaderUI();
            updateModeSwitcherButton();

            State.transitionTo(STATES.LOADING, 'Mode switch complete, reloading');

            await initializeMode(targetModeName);
            await currentMode.start();

            setTimeout(() => {
                if ($btn.length) {
                    $btn.prop('disabled', false).removeAttr('aria-busy');
                }
            }, 1500);
        } catch (err) {
            console.error('Error during mode switch:', err);
            State.forceTransitionTo(STATES.IDLE, 'Mode switch error');
            if ($btn.length) $btn.prop('disabled', false).removeAttr('aria-busy');
        }
    }

    async function initializeMode(modeName) {
        const ModeClass = getModeClass(modeName);
        currentMode = new ModeClass({
            selectedCategories: State.categoryNames
        });

        await currentMode.initialize();
        return currentMode;
    }

    function getModeClass(modeName) {
        const modes = {
            'quiz': root.LLFlashcards.QuizMode,
            'learn': root.LLFlashcards.LearnMode
        };
        return modes[modeName] || modes['quiz'];
    }

    function onCorrectAnswer(targetWord, $correctCard) {
        if (currentMode) {
            currentMode.handleInteraction('correct-answer', {
                targetWord: targetWord,
                $card: $correctCard
            });
        }
    }

    function onWrongAnswer(targetWord, index, $wrongCard) {
        if (currentMode) {
            currentMode.handleInteraction('wrong-answer', {
                targetWord: targetWord,
                index: index,
                $card: $wrongCard
            });
        }
    }

    function startQuizRound(options) {
        if (currentMode) {
            currentMode.startRound(options);
        }
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

    async function initFlashcardWidget(selectedCategories, mode) {
        newSession();
        $('#ll-tools-learning-progress').hide().empty();

        State.transitionTo(STATES.LOADING, 'Widget initialization');

        try {
            await root.FlashcardAudio.startNewSession();

            const targetMode = mode || 'quiz';
            State.isLearningMode = (targetMode === 'learn');

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
                    audio.pause();
                    audio.currentTime = 0;
                    Dom.setRepeatButton('play');
                } else {
                    audio.play().then(() => { Dom.setRepeatButton('stop'); }).catch(() => { });
                    audio.onended = function () { Dom.setRepeatButton('play'); };
                }
            });

            $('#ll-tools-mode-switcher').off('click').on('click', () => switchMode());
            $('#restart-standard-mode').off('click').on('click', () => switchMode('quiz'));
            $('#restart-learning-mode').off('click').on('click', () => switchMode('learn'));
            $('#restart-quiz').off('click').on('click', restartQuiz);

            Dom.showLoading();
            updateModeSwitcherButton();

            await initializeMode(targetMode);
            await currentMode.start();
        } catch (err) {
            console.error('Failed to start flashcard widget:', err);
            State.forceTransitionTo(STATES.IDLE, 'Initialization error');
        }
    }

    function closeFlashcard() {
        State.transitionTo(STATES.CLOSING, 'User closed widget');

        State.abortAllOperations = true;
        State.clearActiveTimeouts();

        root.FlashcardAudio.startNewSession().then(async () => {
            if (root.LLFlashcards?.Results?.hideResults) {
                root.LLFlashcards.Results.hideResults();
            }

            if (currentMode) {
                await currentMode.cleanup();
                currentMode = null;
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
        });
    }

    async function restartQuiz() {
        newSession();
        $('#ll-tools-learning-progress').hide().empty();

        if (currentMode) {
            await currentMode.cleanup();
        }

        State.reset();

        if (root.LLFlashcards?.Results?.hideResults) {
            root.LLFlashcards.Results.hideResults();
        }

        $('#ll-tools-flashcard').empty();
        Dom.restoreHeaderUI();
        updateModeSwitcherButton();

        const modeName = State.isLearningMode ? 'learn' : 'quiz';
        await initializeMode(modeName);

        State.transitionTo(STATES.QUIZ_READY, 'Quiz restarted');
        await currentMode.start();
    }

    if (root.llToolsFlashcardsData && root.llToolsFlashcardsData.categoriesPreselected) {
        root.FlashcardLoader.processFetchedWordData(root.llToolsFlashcardsData.firstCategoryData, State.firstCategoryName);
        root.FlashcardLoader.preloadCategoryResources(State.firstCategoryName);
    }

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Main = {
        initFlashcardWidget,
        startQuizRound,
        onCorrectAnswer,
        onWrongAnswer,
        closeFlashcard,
        restartQuiz,
        switchMode
    };
    root.initFlashcardWidget = initFlashcardWidget;
})(window, jQuery);