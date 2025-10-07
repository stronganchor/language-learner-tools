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
        const State = root.LLFlashcards.State;

        // 1. If we have fewer than 2 introduced words, introduce them
        if (State.introducedWordIDs.length < 2) {
            return getNextWordToIntroduce();
        }

        // 2. Check the repetition queue first (handles wrong answers automatically)
        const queue = State.categoryRepetitionQueues[State.currentCategoryName];
        if (queue && queue.length > 0) {
            // Quiz words from the repetition queue (these had wrong answers)
            const queuedWord = queue[0];
            queue.shift();
            return queuedWord.wordData;
        }

        // 3. Check which words still need practice
        const needMorePractice = State.introducedWordIDs.filter(id => {
            return (State.wordCorrectCounts[id] || 0) < State.MIN_CORRECT_COUNT;
        });

        // 4. If all introduced words have been answered correctly at least once,
        //    introduce a new word
        const allHaveOneCorrect = State.introducedWordIDs.every(id =>
            (State.wordCorrectCounts[id] || 0) >= 1
        );

        if (allHaveOneCorrect &&
            State.wordsToIntroduce.length > 0 &&
            State.introducedWordIDs.length < 12) {
            return getNextWordToIntroduce();
        }

        // 5. Otherwise, quiz on introduced words that need practice
        if (needMorePractice.length > 0) {
            const sortedByCount = needMorePractice.sort((a, b) => {
                const countA = State.wordCorrectCounts[a] || 0;
                const countB = State.wordCorrectCounts[b] || 0;
                return countA - countB;
            });

            const pickFromCount = Math.max(1, Math.ceil(sortedByCount.length / 2));
            const randomIndex = Math.floor(Math.random() * pickFromCount);
            const wordId = sortedByCount[randomIndex];

            for (let name of State.categoryNames) {
                const words = State.wordsByCategory[name];
                if (!words) continue;

                const word = words.find(w => w.id === wordId);
                if (word) {
                    State.currentCategoryName = name;
                    State.currentCategory = words;
                    return word;
                }
            }
        }

        // 6. Fallback: introduce new word if available
        return getNextWordToIntroduce();
    }

    /**
     * Get the next word to introduce
     */
    function getNextWordToIntroduce() {
        const State = root.LLFlashcards.State;

        if (State.wordsToIntroduce.length === 0) {
            return null;
        }

        // On first introduction round, get 2 words at once
        const wordsToIntroduceNow = (State.introducedWordIDs.length === 0)
            ? Math.min(2, State.wordsToIntroduce.length)
            : 1;

        const words = [];
        for (let i = 0; i < wordsToIntroduceNow; i++) {
            const wordId = State.wordsToIntroduce.shift();

            // Find the word in our categories
            for (let name of State.categoryNames) {
                const categoryWords = State.wordsByCategory[name];
                if (!categoryWords) continue;

                const word = categoryWords.find(w => w.id === wordId);
                if (word) {
                    State.introducedWordIDs.push(wordId);
                    State.wordCorrectCounts[wordId] = 0;
                    if (!State.currentCategoryName) {
                        State.currentCategoryName = name;
                        State.currentCategory = categoryWords;
                    }
                    words.push(word);
                    break;
                }
            }
        }

        if (words.length > 0) {
            State.isIntroducingWord = true;
            State.currentIntroductionRound = 0;
            return words; // Return array for multiple words
        }

        return null;
    }

    /**
     * Initialize learning mode word list
     */
    function initializeLearningMode() {
        const State = root.LLFlashcards.State;
        State.wordsToIntroduce = [];

        // Collect all word IDs from all categories
        for (let name of State.categoryNames) {
            const words = State.wordsByCategory[name];
            if (words) {
                words.forEach(word => {
                    if (!State.wordsToIntroduce.includes(word.id)) {
                        State.wordsToIntroduce.push(word.id);
                    }
                });
            }
        }

        // Shuffle the words
        State.wordsToIntroduce = Util.randomlySort(State.wordsToIntroduce);
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
            (State.learningModeOptionsCount || 2) :
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
