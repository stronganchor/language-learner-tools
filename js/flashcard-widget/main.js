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

        root.FlashcardOptions.calculateNumberOfOptions(State.wrongIndexes, State.isFirstRound, State.currentCategoryName);

        const target = Selection.selectTargetWordAndCategory();
        if (!target) { Results.showResults(); return; }

        root.FlashcardLoader.loadResourcesForWord(target, Selection.getCurrentDisplayMode()).then(function () {
            Selection.fillQuizOptions(target);
            root.FlashcardAudio.setTargetWordAudio(target);
            Dom.hideLoading();
        });
    }

    function initFlashcardWidget(selectedCategories) {
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
        State.reset();
        State.categoryNames = [];
        $('#ll-tools-flashcard').empty();
        $('#ll-tools-flashcard-header').hide();
        $('#ll-tools-flashcard-quiz-popup').hide();
        $('#ll-tools-flashcard-popup').hide();
        $('body').removeClass('ll-tools-flashcard-open');
    }

    function restartQuiz() {
        State.reset();
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
