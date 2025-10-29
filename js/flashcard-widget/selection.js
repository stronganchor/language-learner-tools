(function (root) {
    'use strict';
    const { Util, State } = root.LLFlashcards;

    /**
     * Get display mode for a specific category
     */
    function getCategoryDisplayMode(name) {
        if (!name) return State.DEFAULT_DISPLAY_MODE;
        const cat = (root.llToolsFlashcardsData.categories || []).find(c => c.name === name);
        return cat ? cat.mode : State.DEFAULT_DISPLAY_MODE;
    }

    /**
     * Get display mode for current category
     */
    function getCurrentDisplayMode() {
        return getCategoryDisplayMode(State.currentCategoryName);
    }

    /**
     * Find word object by ID across all categories
     */
    function wordObjectById(wordId) {
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
        return null;
    }

    /**
     * Select words from a specific category, avoiding duplicates and similar words
     */
    function selectWordsFromCategory(category, selected) {
        const mode = getCurrentDisplayMode();
        for (let candidate of State.wordsByCategory[category]) {
            const isDup = selected.some(w => w.id === candidate.id);
            const isSim = selected.some(w =>
                w.similar_word_id === String(candidate.id) ||
                candidate.similar_word_id === String(w.id)
            );
            const sameImg = (mode === 'image') && selected.some(w => w.image === candidate.image);

            if (!isDup && !isSim && !sameImg) {
                selected.push(candidate);
                root.FlashcardLoader.loadResourcesForWord(candidate, mode);
                root.LLFlashcards.Cards.appendWordToContainer(candidate);

                if (selected.length >= root.FlashcardOptions.categoryOptionsCount[State.currentCategoryName] ||
                    !root.FlashcardOptions.canAddMoreCards()) {
                    break;
                }
            }
        }
        return selected;
    }

    /**
     * Fill quiz options with target word and additional choices
     */
    function fillQuizOptions(target) {
        const mode = getCurrentDisplayMode();
        root.FlashcardLoader.loadResourcesForWord(target, mode);
        root.LLFlashcards.Cards.appendWordToContainer(target);

        const selected = [target];
        selectWordsFromCategory(State.currentCategoryName, selected);

        const needMore = root.FlashcardOptions.categoryOptionsCount[State.currentCategoryName] - selected.length;
        if (needMore > 0) {
            for (let categoryName of State.categoryNames) {
                if (categoryName === State.currentCategoryName) continue;
                selectWordsFromCategory(categoryName, selected);
                if (selected.length >= root.FlashcardOptions.categoryOptionsCount[State.currentCategoryName]) {
                    break;
                }
            }
        }

        $('.flashcard-container').on('click', function () {
            const $card = $(this);
            const wordId = parseInt($card.attr('data-word-id'), 10);
            const wordIndex = parseInt($card.attr('data-word-index'), 10);
            const isTarget = (wordId === target.id);

            if (isTarget) {
                root.LLFlashcards.Main.onCorrectAnswer(target, $card);
            } else {
                root.LLFlashcards.Main.onWrongAnswer(target, wordIndex, $card);
            }
        });
    }

    // Expose generic selection utilities
    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Selection = {
        getCategoryDisplayMode,
        getCurrentDisplayMode,
        wordObjectById,
        selectWordsFromCategory,
        fillQuizOptions
    };

    // Legacy exports
    root.getCategoryDisplayMode = getCategoryDisplayMode;
    root.getCurrentDisplayMode = getCurrentDisplayMode;
})(window);