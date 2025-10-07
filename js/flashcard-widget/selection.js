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

        if (queue && queue.length) {
            for (let i = 0; i < queue.length; i++) {
                if (queue[i].reappearRound <= (State.categoryRoundCount[candidateCategoryName] || 0)) {
                    target = queue[i].wordData; queue.splice(i, 1); break;
                }
            }
        }
        if (!target) {
            for (let i = 0; i < candidateCategory.length; i++) {
                if (!State.usedWordIDs.includes(candidateCategory[i].id)) {
                    target = candidateCategory[i]; State.usedWordIDs.push(target.id); break;
                }
            }
        }
        if (!target && queue && queue.length) {
            target = queue[0].wordData;
            if (target.id === State.usedWordIDs[State.usedWordIDs.length - 1]) {
                const others = candidateCategory.filter(w => w.id !== target.id);
                if (others.length) target = others[Math.floor(Math.random() * others.length)];
            }
            const qi = queue.findIndex(it => it.wordData.id === target.id);
            if (qi !== -1) queue.splice(qi, 1);
        }

        if (target) {
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
        const S = window.LLFlashcards.State;
        ensureLearningDefaults();
        S.turnIndex++;

        // 0) Intro bootstrap: until we have two, keep introducing
        if (S.introducedWordIDs.length < 2) {
            return getNextWordToIntroduce();
        }

        // 1) Current status flags
        const hasPendingWrongs = (S.wrongAnswerQueue.length > 0);
        const everyoneAnsweredThisCycle = S.introducedWordIDs.length > 0 &&
            S.introducedWordIDs.every(id => S.wordsAnsweredSinceLastIntro.has(id));

        const nothingLeftToIntroduce = (S.wordsToIntroduce.length === 0);

        // 2) FINISH CONDITION (prevents endless loop)
        // After last introduction, all words must reach MIN_CORRECT_COUNT with no wrongs pending
        const allWordsComplete = S.introducedWordIDs.every(id =>
            (S.wordCorrectCounts[id] || 0) >= S.MIN_CORRECT_COUNT
        );

        if (nothingLeftToIntroduce && !hasPendingWrongs && allWordsComplete) {
            return null; // main.js will call Results.showResults()
        }

        // 3) May we introduce a new word this turn?
        const canIntroduceMore = (S.introducedWordIDs.length < 12);
        const mayIntroduce = !hasPendingWrongs &&
            everyoneAnsweredThisCycle &&
            !nothingLeftToIntroduce &&
            canIntroduceMore;

        if (mayIntroduce) {
            return getNextWordToIntroduce();
        }

        // 4) Choose a practice target
        let wordId = null;

        if (hasPendingWrongs) {
            // Prefer a wrong, but avoid immediate repeats when possible
            const wrongId = pickWrongWithSpacing();

            if (wrongId === S.lastWordShownId) {
                const altPool = S.introducedWordIDs.filter(id => id !== wrongId && id !== S.lastWordShownId);
                if (altPool.length) {
                    const shuffled = window.LLFlashcards.Util.randomlySort(altPool);
                    wordId = shuffled[0]; // interleave once before we show the wrong again
                } else {
                    wordId = wrongId;
                }
            } else {
                wordId = wrongId;
            }
        } else {
            // No wrongs pending: prioritize those not yet answered (correct) this cycle
            let pool = S.introducedWordIDs.filter(id => !S.wordsAnsweredSinceLastIntro.has(id));

            if (pool.length === 0) {
                // Everyone has at least one correct this cycle; bias toward least-known if any < MIN_CORRECT_COUNT
                const needMorePractice = S.introducedWordIDs.filter(
                    id => (S.wordCorrectCounts[id] || 0) < S.MIN_CORRECT_COUNT
                );
                if (needMorePractice.length > 0) {
                    const minCount = Math.min(...needMorePractice.map(id => S.wordCorrectCounts[id] || 0));
                    pool = needMorePractice.filter(id => (S.wordCorrectCounts[id] || 0) === minCount);

                    // Randomly sprinkle in 1-3 completed words to make practice less predictable
                    const completedWords = S.introducedWordIDs.filter(id =>
                        (S.wordCorrectCounts[id] || 0) >= S.MIN_CORRECT_COUNT
                    );
                    if (completedWords.length > 0) {
                        // Randomly decide how many completed words to sprinkle (1-3)
                        const sprinkleCount = Math.floor(Math.random() * 3) + 1; // 1, 2, or 3
                        const shuffled = window.LLFlashcards.Util.randomlySort(completedWords);
                        const toSprinkle = shuffled.slice(0, Math.min(sprinkleCount, completedWords.length));
                        pool = [...pool, ...toSprinkle];
                    }
                } else {
                    // All meet the minimum: just use all introduced to finish the cycle
                    pool = [...S.introducedWordIDs];
                }
            }

            // Avoid immediate repeat if possible
            if (pool.length > 1 && S.lastWordShownId) {
                const filtered = pool.filter(id => id !== S.lastWordShownId);
                if (filtered.length) pool = filtered;
            }

            pool = window.LLFlashcards.Util.randomlySort(pool);
            wordId = pool[Math.floor(Math.random() * pool.length)];
        }

        S.lastWordShownId = wordId;
        const word = wordObjectById(wordId);
        if (word) return word;

        // 5) Fallback — try introducing if we somehow couldn't resolve a word
        return getNextWordToIntroduce();
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
        const State = window.LLFlashcards.State;
        State.wordsToIntroduce = [];
        const seen = new Set();

        for (let name of State.categoryNames) {
            const words = State.wordsByCategory[name];
            if (!words) continue;
            for (const w of words) {
                if (!seen.has(w.id)) {
                    seen.add(w.id);
                    State.wordsToIntroduce.push(w.id);
                }
            }
        }
        State.wordsToIntroduce = window.LLFlashcards.Util.randomlySort(State.wordsToIntroduce);
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
            // Standard mode: build order as before
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
        recordAnswerResult(wordId, isCorrect) {
            const S = window.LLFlashcards.State;
            ensureLearningDefaults();

            if (isCorrect) {
                // Track corrects
                S.wordCorrectCounts[wordId] = (S.wordCorrectCounts[wordId] || 0) + 1;
                S.wordsAnsweredSinceLastIntro.add(wordId);

                // If this card was in the wrong queue, remove it (we "redeemed" it)
                removeFromWrongQueue(wordId);

                // Streak up => maybe grow choices
                S.learningCorrectStreak += 1;
                const t = S.learningCorrectStreak;
                const target =
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
        if (typeof S.MAX_CHOICE_COUNT !== 'number') S.MAX_CHOICE_COUNT = 5;
        if (!S.MIN_CORRECT_COUNT) S.MIN_CORRECT_COUNT = 1;
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
