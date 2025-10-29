(function (root) {
    'use strict';

    /**
     * LearnState - Manages state specific to learning mode
     */
    const LearnState = {
        // Learning mode data
        introducedWordIDs: [],
        wordIntroductionProgress: {},
        wordCorrectCounts: {},
        wordsToIntroduce: [],
        totalWordCount: 0,
        wrongAnswerQueue: [],
        hadWrongAnswerThisTurn: false,
        isIntroducingWord: false,
        currentIntroductionAudio: null,
        currentIntroductionRound: 0,
        learningModeOptionsCount: 2,
        learningModeConsecutiveCorrect: 0,
        wordsAnsweredSinceLastIntro: new Set(),
        lastWordShownId: null,
        learningModeRepetitionQueue: [],

        reset() {
            this.introducedWordIDs = [];
            this.wordIntroductionProgress = {};
            this.wordCorrectCounts = {};
            this.wordsToIntroduce = [];
            this.totalWordCount = 0;
            this.wrongAnswerQueue = [];
            this.hadWrongAnswerThisTurn = false;
            this.isIntroducingWord = false;
            this.currentIntroductionAudio = null;
            this.currentIntroductionRound = 0;
            this.learningModeOptionsCount = 2;
            this.learningModeConsecutiveCorrect = 0;
            this.wordsAnsweredSinceLastIntro = new Set();
            this.lastWordShownId = null;
            this.learningModeRepetitionQueue = [];
        }
    };

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.LearnState = LearnState;
})(window);