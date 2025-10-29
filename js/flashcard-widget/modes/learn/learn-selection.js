(function (root) {
    'use strict';

    const State = root.LLFlashcards.State;
    const LearnState = root.LLFlashcards.LearnState;
    const Selection = root.LLFlashcards.Selection;
    const Util = root.LLFlashcards.Util;

    /**
     * Select next word for learning mode (intro or practice)
     */
    function selectLearningModeWord() {
        ensureLearningDefaults();
        LearnState.turnIndex = (LearnState.turnIndex || 0) + 1;

        // Bootstrap: introduce first 2 words
        if (LearnState.introducedWordIDs.length < 2) {
            return getNextWordToIntroduce();
        }

        const hasPendingWrongs = (LearnState.wrongAnswerQueue.length > 0);
        const everyoneAnsweredThisCycle = LearnState.introducedWordIDs.length > 0 &&
            LearnState.introducedWordIDs.every(id => LearnState.wordsAnsweredSinceLastIntro.has(id));

        const nothingLeftToIntroduce = (LearnState.wordsToIntroduce.length === 0);
        const allWordsCompleted = LearnState.introducedWordIDs.every(
            id => (LearnState.wordCorrectCounts[id] || 0) >= LearnState.MIN_CORRECT_COUNT
        );

        // Completion check
        if (nothingLeftToIntroduce && !hasPendingWrongs && allWordsCompleted) {
            return null;
        }

        // Should we introduce a new word?
        const canIntroduceMore = (LearnState.introducedWordIDs.length < 12);
        const mayIntroduce = !hasPendingWrongs &&
            everyoneAnsweredThisCycle &&
            !nothingLeftToIntroduce &&
            canIntroduceMore;

        if (mayIntroduce) {
            return getNextWordToIntroduce();
        }

        // Select a practice word
        let wordId = null;

        if (hasPendingWrongs) {
            const wrongId = pickWrongWithSpacing();

            if (wrongId === State.lastWordShownId) {
                const altPool = LearnState.introducedWordIDs.filter(
                    id => id !== wrongId && id !== State.lastWordShownId
                );
                if (altPool.length) {
                    const shuffled = Util.randomlySort(altPool);
                    wordId = shuffled[0];
                } else {
                    wordId = wrongId;
                }
            } else {
                wordId = wrongId;
            }
        } else {
            const pool = LearnState.introducedWordIDs.filter(id => id !== State.lastWordShownId);
            if (!pool.length) {
                wordId = LearnState.introducedWordIDs[0];
            } else {
                const shuffled = Util.randomlySort(pool);
                wordId = shuffled[0];
            }
        }

        const word = Selection.wordObjectById(wordId);
        if (word) {
            LearnState.wordsAnsweredSinceLastIntro.add(wordId);
            State.lastWordShownId = wordId;
        }
        return word;
    }

    /**
     * Get the next word to introduce
     */
    function getNextWordToIntroduce() {
        if (!LearnState.wordsToIntroduce || LearnState.wordsToIntroduce.length === 0) {
            return null;
        }

        const nextId = LearnState.wordsToIntroduce.shift();
        const word = Selection.wordObjectById(nextId);

        if (word) {
            LearnState.introducedWordIDs.push(nextId);
            LearnState.wordsAnsweredSinceLastIntro.clear();
            State.lastWordShownId = nextId;
            State.isIntroducingWord = true;
        }

        return word;
    }

    /**
     * Initialize learning mode state and word queue
     */
    function initializeLearningMode() {
        ensureLearningDefaults();

        const allWordIds = [];
        for (let catName of State.categoryNames) {
            const words = State.wordsByCategory[catName];
            if (words && words.length) {
                words.forEach(w => allWordIds.push(w.id));
            }
        }

        const shuffled = Util.randomlySort(allWordIds);
        LearnState.wordsToIntroduce = shuffled;
        LearnState.totalWordCount = shuffled.length;
        LearnState.introducedWordIDs = [];
        LearnState.wordsAnsweredSinceLastIntro = new Set();
        LearnState.wrongAnswerQueue = [];
        LearnState.wordCorrectCounts = {};
        LearnState.wordIntroductionProgress = {};
        LearnState.turnIndex = 0;
        LearnState.learningCorrectStreak = 0;
        LearnState.learningChoiceCount = 2;
    }

    /**
     * Record answer result and adjust difficulty
     */
    function recordAnswerResult(wordId, isCorrect, hadWrongAnswerThisTurn) {
        ensureLearningDefaults();

        if (isCorrect) {
            LearnState.wordCorrectCounts[wordId] = (LearnState.wordCorrectCounts[wordId] || 0) + 1;

            if (!hadWrongAnswerThisTurn) {
                removeFromWrongQueue(wordId);
            }

            LearnState.learningCorrectStreak = (LearnState.learningCorrectStreak || 0) + 1;
            const t = LearnState.learningCorrectStreak;

            const target =
                (t >= 13) ? 6 :
                    (t >= 10) ? 5 :
                        (t >= 6) ? 4 :
                            (t >= 3) ? 3 : 2;

            const uncappedChoice = Math.min(target, LearnState.MAX_CHOICE_COUNT);
            LearnState.learningChoiceCount = applyLearningModeConstraints(uncappedChoice);

        } else {
            if (!LearnState.wrongAnswerQueue.includes(wordId)) {
                LearnState.wrongAnswerQueue.push(wordId);
            }
            LearnState.learningCorrectStreak = 0;
            const reduced = Math.max(LearnState.MIN_CHOICE_COUNT, LearnState.learningChoiceCount - 1);
            LearnState.learningChoiceCount = applyLearningModeConstraints(reduced);
        }

        LearnState.currentChoiceCount = LearnState.learningChoiceCount;
    }

    /**
     * Get current choice count for learning mode
     */
    function getChoiceCount() {
        ensureLearningDefaults();
        return applyLearningModeConstraints(LearnState.learningChoiceCount);
    }

    // === Helper Functions ===

    function ensureLearningDefaults() {
        if (!LearnState.wordsAnsweredSinceLastIntro) LearnState.wordsAnsweredSinceLastIntro = new Set();
        if (!LearnState.wordCorrectCounts) LearnState.wordCorrectCounts = {};
        if (!Array.isArray(LearnState.wrongAnswerQueue)) LearnState.wrongAnswerQueue = [];
        if (typeof LearnState.turnIndex !== 'number') LearnState.turnIndex = 0;
        if (typeof LearnState.learningCorrectStreak !== 'number') LearnState.learningCorrectStreak = 0;
        if (typeof LearnState.learningChoiceCount !== 'number') LearnState.learningChoiceCount = 2;
        if (typeof LearnState.MIN_CHOICE_COUNT !== 'number') LearnState.MIN_CHOICE_COUNT = 2;
        if (typeof LearnState.MAX_CHOICE_COUNT !== 'number') LearnState.MAX_CHOICE_COUNT = 6;
        if (typeof LearnState.MIN_CORRECT_COUNT !== 'number') LearnState.MIN_CORRECT_COUNT = 3;
    }

    function applyLearningModeConstraints(desiredCount) {
        const mode = Selection.getCurrentDisplayMode();

        let maxCount = (root.llToolsFlashcardsData.maxOptionsOverride)
            ? parseInt(root.llToolsFlashcardsData.maxOptionsOverride, 10)
            : 9;

        if (mode === 'text') {
            maxCount = Math.min(maxCount, 4);
        }

        const introducedCount = LearnState.introducedWordIDs.length;
        maxCount = Math.min(maxCount, introducedCount);
        maxCount = Math.max(2, maxCount);

        return Math.min(desiredCount, maxCount);
    }

    function removeFromWrongQueue(id) {
        LearnState.wrongAnswerQueue = LearnState.wrongAnswerQueue.filter(x => x !== id);
    }

    function pickWrongWithSpacing() {
        for (let i = 0; i < LearnState.wrongAnswerQueue.length; i++) {
            if (LearnState.wrongAnswerQueue[i] !== State.lastWordShownId) {
                return LearnState.wrongAnswerQueue[i];
            }
        }
        return LearnState.wrongAnswerQueue[0];
    }

    // Expose learning selection functions
    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.LearnSelection = {
        selectLearningModeWord,
        getNextWordToIntroduce,
        initializeLearningMode,
        recordAnswerResult,
        getChoiceCount
    };
})(window);