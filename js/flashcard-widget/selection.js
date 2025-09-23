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
        selectTargetWordAndCategory, fillQuizOptions
    };

    // legacy exports
    root.getCategoryDisplayMode = getCategoryDisplayMode;
    root.getCurrentDisplayMode = getCurrentDisplayMode;
})(window);
