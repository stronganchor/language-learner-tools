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

        // Check if any introduced words still need to reach MIN_CORRECT_COUNT
        const needMorePractice = State.introducedWordIDs.filter(id => {
            return (State.wordCorrectCounts[id] || 0) < State.MIN_CORRECT_COUNT;
        });

        // If we have fewer than 2 introduced words, introduce new ones
        if (State.introducedWordIDs.length < 2) {
            return getNextWordToIntroduce();
        }

        // Once we have 2+ words introduced, quiz them until they reach MIN_CORRECT_COUNT
        // Only introduce new words once all current words have at least 1 correct answer
        const allHaveOneCorrect = State.introducedWordIDs.every(id =>
            (State.wordCorrectCounts[id] || 0) >= 1
        );

        // Add new words one at a time after initial introduction
        if (allHaveOneCorrect && State.wordsToIntroduce.length > 0 && State.introducedWordIDs.length < 12) {
            return getNextWordToIntroduce();
        }

        // Quiz on introduced words
        if (needMorePractice.length > 0) {
            const sortedByCount = needMorePractice.sort((a, b) => {
                const countA = State.wordCorrectCounts[a] || 0;
                const countB = State.wordCorrectCounts[b] || 0;
                return countA - countB;
            });

            for (let name of State.categoryNames) {
                const words = State.wordsByCategory[name];
                if (!words) continue;

                const word = words.find(w => w.id === sortedByCount[0]);
                if (word) {
                    State.currentCategoryName = name;
                    State.currentCategory = words;
                    return word;
                }
            }
        }

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
        const order = [];
        order.push(State.currentCategoryName);
        if (targetWord.all_categories) {
            for (let c of targetWord.all_categories) if (!order.includes(c)) order.push(c);
        }
        State.categoryNames.forEach(c => { if (!order.includes(c)) order.push(c); });

        root.FlashcardLoader.loadResourcesForWord(targetWord, getCategoryDisplayMode());
        chosen.push(targetWord);
        root.LLFlashcards.Cards.appendWordToContainer(targetWord);

        while (chosen.length < root.FlashcardOptions.categoryOptionsCount[State.currentCategoryName] &&
            root.FlashcardOptions.canAddMoreCards()) {
            if (!order.length) break;
            const candidateName = order.shift();
            if (!State.wordsByCategory[candidateName] || root.FlashcardLoader.loadedCategories.includes(candidateName)) {
                root.FlashcardLoader.loadResourcesForCategory(candidateName);
            }
            State.wordsByCategory[candidateName] = Util.randomlySort(State.wordsByCategory[candidateName]);
            chosen = selectWordsFromCategory(candidateName, chosen);
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
