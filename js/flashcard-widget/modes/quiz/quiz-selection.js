(function (root) {
    'use strict';

    const State = root.LLFlashcards.State;
    const QuizState = root.LLFlashcards.QuizState;
    const Selection = root.LLFlashcards.Selection;

    /**
     * Select target word from a specific category, checking repetition queue first
     */
    function selectTargetWord(candidateCategory, candidateCategoryName) {
        if (!candidateCategory) return null;
        let target = null;

        const queue = QuizState.categoryRepetitionQueues[candidateCategoryName];

        // First, check repetition queue for words ready to reappear
        if (queue && queue.length) {
            for (let i = 0; i < queue.length; i++) {
                if (queue[i].reappearRound <= (State.categoryRoundCount[candidateCategoryName] || 0)) {
                    if (queue[i].wordData.id !== State.lastWordShownId) {
                        target = queue[i].wordData;
                        queue.splice(i, 1);
                        break;
                    }
                }
            }
        }

        // If no target from queue, find an unused word
        if (!target) {
            for (let i = 0; i < candidateCategory.length; i++) {
                if (!State.usedWordIDs.includes(candidateCategory[i].id) &&
                    candidateCategory[i].id !== State.lastWordShownId) {
                    target = candidateCategory[i];
                    State.usedWordIDs.push(target.id);
                    break;
                }
            }
        }

        // Fallback: pick from queue but avoid last shown
        if (!target && queue && queue.length) {
            let queueCandidate = queue.find(item => item.wordData.id !== State.lastWordShownId);

            if (!queueCandidate && queue.length > 0) {
                const others = candidateCategory.filter(w => w.id !== State.lastWordShownId);
                if (others.length) {
                    target = others[Math.floor(Math.random() * others.length)];
                } else {
                    queueCandidate = queue[0];
                }
            }

            if (queueCandidate) {
                target = queueCandidate.wordData;
                const qi = queue.findIndex(it => it.wordData.id === target.id);
                if (qi !== -1) queue.splice(qi, 1);
            }
        }

        if (target) {
            State.lastWordShownId = target.id;

            if (State.currentCategoryName !== candidateCategoryName) {
                State.currentCategoryName = candidateCategoryName;
                State.currentCategoryRoundCount = 0;
                root.FlashcardLoader.preloadNextCategories && root.FlashcardLoader.preloadNextCategories();
                root.LLFlashcards.Dom.updateCategoryNameDisplay(State.currentCategoryName);
            }

            State.currentCategory = State.wordsByCategory[candidateCategoryName];
            State.categoryRoundCount[candidateCategoryName] = (State.categoryRoundCount[candidateCategoryName] || 0) + 1;
            State.currentCategoryRoundCount++;
        }

        return target;
    }

    /**
     * Try to select a word from the next available category
     */
    function selectWordFromNextCategory() {
        for (let name of State.categoryNames) {
            const w = selectTargetWord(State.wordsByCategory[name], name);
            if (w) return w;
        }
        return null;
    }

    /**
     * Select target word and manage category rotation
     */
    function selectTargetWordAndCategory() {
        let target = null;

        if (State.isFirstRound) {
            if (!State.firstCategoryName) {
                State.firstCategoryName = State.categoryNames[Math.floor(Math.random() * State.categoryNames.length)];
            }
            target = selectTargetWord(State.wordsByCategory[State.firstCategoryName], State.firstCategoryName);
            State.currentCategoryName = State.firstCategoryName;
            State.currentCategory = State.wordsByCategory[State.currentCategoryName];
        } else {
            const queue = QuizState.categoryRepetitionQueues[State.currentCategoryName];

            if ((queue && queue.length) || State.currentCategoryRoundCount <= State.ROUNDS_PER_CATEGORY) {
                target = selectTargetWord(State.currentCategory, State.currentCategoryName);
            } else {
                // Rotate to next category
                const i = State.categoryNames.indexOf(State.currentCategoryName);
                if (i > -1) {
                    State.categoryNames.splice(i, 1);
                    State.categoryNames.push(State.currentCategoryName);
                }
                State.categoryRoundCount[State.currentCategoryName] = 0;
                State.currentCategoryRoundCount = 0;
            }
        }

        if (!target) target = selectWordFromNextCategory();
        return target;
    }

    // Expose quiz selection functions
    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.QuizSelection = {
        selectTargetWordAndCategory,
        selectTargetWord,
        selectWordFromNextCategory
    };
})(window);