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
        try {
            if (window.FlashcardOptions && typeof window.FlashcardOptions.ensureResponsiveSize === 'function') {
                window.FlashcardOptions.ensureResponsiveSize(2);
            }
        } catch (_) { }

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

        try {
            if (window.FlashcardOptions && typeof window.FlashcardOptions.ensureResponsiveSize === 'function') {
                window.FlashcardOptions.ensureResponsiveSize(2);
            }
        } catch (_) { }

        jQuery('.flashcard-container').each(function (idx) {
            root.LLFlashcards.Cards.addClickEventToCard(jQuery(this), idx, targetWord);
        });
        jQuery('.flashcard-container').hide().fadeIn(600);
    }

    window.LLFlashcards = window.LLFlashcards || {};
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
