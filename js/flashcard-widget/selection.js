(function (root) {
    'use strict';
    const { Util, State } = root.LLFlashcards;

    function getCategoryDisplayMode(name) {
        if (!name) return State.DEFAULT_DISPLAY_MODE;
        const cat = (root.llToolsFlashcardsData.categories || []).find(c => c.name === name);
        return cat ? cat.mode : State.DEFAULT_DISPLAY_MODE;
    }
    function getCurrentDisplayMode() { return getCategoryDisplayMode(State.currentCategoryName); }

    function selectTargetWord(candidateCategory, candidateCategoryName) {
        if (!candidateCategory) return null;
        let target = null;
        const queue = State.categoryRepetitionQueues[candidateCategoryName];

        // First, try to find a word from the repetition queue that's ready to reappear
        // and ISN'T the same as the last word shown
        if (queue && queue.length) {
            for (let i = 0; i < queue.length; i++) {
                if (queue[i].reappearRound <= (State.categoryRoundCount[candidateCategoryName] || 0)) {
                    // Skip if this is the same word we just showed
                    if (queue[i].wordData.id !== State.lastWordShownId) {
                        target = queue[i].wordData;
                        queue.splice(i, 1);
                        break;
                    }
                }
            }
        }

        // If no target from queue, try to find an unused word (and not the last shown)
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

        // Fallback: if still no target and queue exists, pick from queue but avoid last shown
        if (!target && queue && queue.length) {
            // Try to find a word that isn't the last shown
            let queueCandidate = queue.find(item => item.wordData.id !== State.lastWordShownId);

            if (!queueCandidate && queue.length > 0) {
                // All queue items are the last shown word, or only one word in queue
                // Try to find any other word from the category
                const others = candidateCategory.filter(w => w.id !== State.lastWordShownId);
                if (others.length) {
                    target = others[Math.floor(Math.random() * others.length)];
                } else {
                    // No choice but to use the queue item (only happens with very small word sets)
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
            // Update last shown word ID to prevent consecutive duplicates
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

    function selectWordFromNextCategory() {
        for (let name of State.categoryNames) {
            const w = selectTargetWord(State.wordsByCategory[name], name);
            if (w) return w;
        }
        return null;
    }

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
            const queue = State.categoryRepetitionQueues[State.currentCategoryName];
            if ((queue && queue.length) || State.currentCategoryRoundCount <= State.ROUNDS_PER_CATEGORY) {
                target = selectTargetWord(State.currentCategory, State.currentCategoryName);
            } else {
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

    function selectWordsFromCategory(category, selected) {
        const mode = getCurrentDisplayMode();
        for (let candidate of State.wordsByCategory[category]) {
            const isDup = selected.some(w => w.id === candidate.id);
            const isSim = selected.some(w => w.similar_word_id === String(candidate.id) || candidate.similar_word_id === String(w.id));
            const sameImg = (mode === 'image') && selected.some(w => w.image === candidate.image);
            if (!isDup && !isSim && !sameImg) {
                selected.push(candidate);
                root.FlashcardLoader.loadResourcesForWord(candidate, mode);
                root.LLFlashcards.Cards.appendWordToContainer(candidate);
                if (selected.length >= root.FlashcardOptions.categoryOptionsCount[State.currentCategoryName] ||
                    !root.FlashcardOptions.canAddMoreCards()) break;
            }
        }
        return selected;
    }

    /**
     * Learning Mode: Select next word to introduce or quiz
     */
    function selectLearningModeWord() {
        // Delegates to module to avoid mixing mode-specific logic here
        return window.LLFlashcards && window.LLFlashcards.Modes && window.LLFlashcards.Modes.Learning
            ? window.LLFlashcards.Modes.Learning.selectTargetWord()
            : null;
    }


    /**
     * Get the next word to introduce
     */
    function getNextWordToIntroduce() {
        const S = window.LLFlashcards.State;
        ensureLearningDefaults();

        // Never re-introduce already introduced IDs
        while (S.wordsToIntroduce.length &&
            S.introducedWordIDs.includes(S.wordsToIntroduce[0])) {
            S.wordsToIntroduce.shift();
        }
        if (S.wordsToIntroduce.length === 0) return null;

        // New cycle begins on intro: user must answer all introduced words correctly again
        S.wordsAnsweredSinceLastIntro.clear();

        // First intro: 2 words; thereafter: 1 at a time
        const introduceNow = (S.introducedWordIDs.length === 0) ? Math.min(2, S.wordsToIntroduce.length) : 1;

        const batch = [];
        for (let i = 0; i < introduceNow; i++) {
            while (S.wordsToIntroduce.length &&
                S.introducedWordIDs.includes(S.wordsToIntroduce[0])) {
                S.wordsToIntroduce.shift();
            }
            if (!S.wordsToIntroduce.length) break;

            const wordId = S.wordsToIntroduce.shift();
            const word = wordObjectById(wordId);
            if (word) {
                S.wordCorrectCounts[wordId] = S.wordCorrectCounts[wordId] || 0;
                batch.push(word);
            }
        }

        if (batch.length > 0) {
            S.isIntroducingWord = true;
            S.currentIntroductionRound = 0;
            return (batch.length === 1 ? batch[0] : batch); // array only on very first pass
        }
        return null;
    }

    /**
     * Initialize learning mode word list
     */
    function initializeLearningMode() {
        return window.LLFlashcards && window.LLFlashcards.Modes && window.LLFlashcards.Modes.Learning
            ? window.LLFlashcards.Modes.Learning.initialize()
            : false;
    }

    function fillQuizOptions(targetWord) {
        let chosen = [];
        const mode = getCurrentDisplayMode();

        // In learning mode, only select from introduced words
        let availableWords = [];
        if (State.isLearningMode) {
            // Collect all introduced words from all categories
            State.categoryNames.forEach(catName => {
                const catWords = State.wordsByCategory[catName] || [];
                catWords.forEach(word => {
                    if (State.introducedWordIDs.includes(word.id)) {
                        availableWords.push(word);
                    }
                });
            });
        } else {
            // Practice mode: build order as before
            const order = [];
            order.push(State.currentCategoryName);
            if (targetWord.all_categories) {
                for (let c of targetWord.all_categories) if (!order.includes(c)) order.push(c);
            }
            State.categoryNames.forEach(c => { if (!order.includes(c)) order.push(c); });

            // Flatten to availableWords
            order.forEach(catName => {
                const catWords = State.wordsByCategory[catName] || [];
                availableWords.push(...catWords);
            });
        }

        // Shuffle available words
        availableWords = Util.randomlySort(availableWords);

        // Add target word first
        root.FlashcardLoader.loadResourcesForWord(targetWord, mode);
        chosen.push(targetWord);
        root.LLFlashcards.Cards.appendWordToContainer(targetWord);

        // Determine how many options to show
        let targetCount = State.isLearningMode ?
            root.LLFlashcards.LearningMode.getChoiceCount() :
            root.FlashcardOptions.categoryOptionsCount[State.currentCategoryName];

        // Fill remaining options from available words
        for (let candidate of availableWords) {
            if (chosen.length >= targetCount) break;
            if (!root.FlashcardOptions.canAddMoreCards()) break;

            const isDup = chosen.some(w => w.id === candidate.id);
            const isSim = chosen.some(w => w.similar_word_id === String(candidate.id) || candidate.similar_word_id === String(w.id));
            const sameImg = (mode === 'image') && chosen.some(w => w.image === candidate.image);

            if (!isDup && !isSim && !sameImg) {
                chosen.push(candidate);
                root.FlashcardLoader.loadResourcesForWord(candidate, mode);
                root.LLFlashcards.Cards.appendWordToContainer(candidate);
            }
        }

        jQuery('.flashcard-container').each(function (idx) {
            root.LLFlashcards.Cards.addClickEventToCard(jQuery(this), idx, targetWord);
        });
        jQuery('.flashcard-container').hide().fadeIn(600);
    }

    window.LLFlashcards = window.LLFlashcards || {};
    window.LLFlashcards.LearningMode = {
        // Call this right after the user answers a card in learning mode.
        // wordId: numeric/string ID of the target word
        // isCorrect: boolean
        recordAnswerResult(wordId, isCorrect, hadWrongThisTurn = false) {
            const S = window.LLFlashcards.State;
            ensureLearningDefaults();

            if (isCorrect) {
                // Only count toward mastery goal if answered correctly on first try
                if (!hadWrongThisTurn) {
                    S.wordCorrectCounts[wordId] = (S.wordCorrectCounts[wordId] || 0) + 1;
                }
                S.wordsAnsweredSinceLastIntro.add(wordId);

                // If this card was in the wrong queue, remove it (we "redeemed" it)
                if (!hadWrongThisTurn) {
                    removeFromWrongQueue(wordId);
                }

                // Streak up => maybe grow choices
                S.learningCorrectStreak += 1;
                const t = S.learningCorrectStreak;

                // Allow up to 6 choices at higher streaks
                const target =
                    (t >= 13) ? 6 :
                        (t >= 10) ? 5 :
                            (t >= 6) ? 4 :
                                (t >= 3) ? 3 : 2;

                // Apply constraints before setting the choice count
                const uncappedChoice = Math.min(target, S.MAX_CHOICE_COUNT);
                S.learningChoiceCount = applyLearningModeConstraints(uncappedChoice);

            } else {
                // Miss ⇒ queue it (if not already queued)
                if (!S.wrongAnswerQueue.includes(wordId)) {
                    S.wrongAnswerQueue.push(wordId);
                }
                // Streak reset and make the task a little easier
                S.learningCorrectStreak = 0;
                const reduced = Math.max(S.MIN_CHOICE_COUNT, S.learningChoiceCount - 1);
                S.learningChoiceCount = applyLearningModeConstraints(reduced);
            }

            // Expose current choice count for any UI that reads it
            S.currentChoiceCount = S.learningChoiceCount;
        },

        // Use this when rendering choices for learning mode
        getChoiceCount() {
            const S = window.LLFlashcards.State;
            ensureLearningDefaults();
            return applyLearningModeConstraints(S.learningChoiceCount);
        }
    };

    // ---- Learning mode helpers (idempotent) ------------------------------------
    function ensureLearningDefaults() {
        const S = window.LLFlashcards.State;
        if (!S.wordsAnsweredSinceLastIntro) S.wordsAnsweredSinceLastIntro = new Set();
        if (!S.wordCorrectCounts) S.wordCorrectCounts = {};
        if (!Array.isArray(S.wrongAnswerQueue)) S.wrongAnswerQueue = [];
        if (typeof S.turnIndex !== 'number') S.turnIndex = 0;
        if (typeof S.learningCorrectStreak !== 'number') S.learningCorrectStreak = 0;
        if (typeof S.learningChoiceCount !== 'number') S.learningChoiceCount = 2;
        if (typeof S.MIN_CHOICE_COUNT !== 'number') S.MIN_CHOICE_COUNT = 2;
        if (typeof S.MAX_CHOICE_COUNT !== 'number') S.MAX_CHOICE_COUNT = 6;
        if (typeof S.MIN_CORRECT_COUNT !== 'number') S.MIN_CORRECT_COUNT = 3;
    }

    /**
     * Apply constraints to learning mode choice count
     * Respects: introduced word count, screen space, site-wide max, and text mode limits
     */
    function applyLearningModeConstraints(desiredCount) {
        const S = window.LLFlashcards.State;
        const mode = getCurrentDisplayMode();

        // Start with site-wide maximum
        let maxCount = (root.llToolsFlashcardsData.maxOptionsOverride)
            ? parseInt(root.llToolsFlashcardsData.maxOptionsOverride, 10)
            : 9;

        // Text mode has stricter limits
        if (mode === 'text') {
            maxCount = Math.min(maxCount, 4);
        }

        // Can't show more options than introduced words
        const introducedCount = S.introducedWordIDs.length;
        maxCount = Math.min(maxCount, introducedCount);

        // Apply screen space constraint
        // Note: This is a rough estimate since canAddMoreCards checks after adding cards
        // We'll still check in the fill loop, but this gives us a better starting target

        // Ensure minimum of 2
        maxCount = Math.max(2, maxCount);

        // Return the constrained count
        return Math.min(desiredCount, maxCount);
    }

    function removeFromWrongQueue(id) {
        const S = window.LLFlashcards.State;
        S.wrongAnswerQueue = S.wrongAnswerQueue.filter(x => x !== id);
    }

    function pickWrongWithSpacing() {
        const S = window.LLFlashcards.State;
        // Prefer the first wrong that isn't the same as last shown
        for (let i = 0; i < S.wrongAnswerQueue.length; i++) {
            if (S.wrongAnswerQueue[i] !== S.lastWordShownId) return S.wrongAnswerQueue[i];
        }
        // If unavoidable, take the head
        return S.wrongAnswerQueue[0];
    }

    function wordObjectById(wordId) {
        const S = window.LLFlashcards.State;
        for (let name of S.categoryNames) {
            const words = S.wordsByCategory[name];
            if (!words) continue;
            const word = words.find(w => w.id === wordId);
            if (word) {
                S.currentCategoryName = name;
                S.currentCategory = words;
                return word;
            }
        }
        return null;
    }

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Selection = {
        getCategoryDisplayMode, getCurrentDisplayMode,
        selectTargetWordAndCategory, fillQuizOptions,
        selectLearningModeWord, initializeLearningMode
    };

    // legacy exports
    root.getCategoryDisplayMode = getCategoryDisplayMode;
    root.getCurrentDisplayMode = getCurrentDisplayMode;
})(window);
