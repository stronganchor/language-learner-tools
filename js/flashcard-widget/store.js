// Canonical flashcard store + back-compat aliases
(function (w) {
    w.LLFlashcards = w.LLFlashcards || {};
    const S = (w.LLFlashcards.Store = w.LLFlashcards.Store || {});

    S.wordsByCategory = S.wordsByCategory || Object.create(null);
    S.categoryRoundCount = S.categoryRoundCount || Object.create(null);
    S.loadedCategories = S.loadedCategories || [];

    // Optional helper
    S.resetCategory = function (cat) {
        S.wordsByCategory[cat] = [];
        if (!(cat in S.categoryRoundCount)) S.categoryRoundCount[cat] = 0;
        if (!S.loadedCategories.includes(cat)) S.loadedCategories.push(cat);
    };

    // Back-compat aliases for legacy globals
    Object.defineProperty(w, 'wordsByCategory', {
        configurable: true, get() { return S.wordsByCategory; }, set(v) { S.wordsByCategory = v; }
    });
    Object.defineProperty(w, 'categoryRoundCount', {
        configurable: true, get() { return S.categoryRoundCount; }, set(v) { S.categoryRoundCount = v; }
    });
    Object.defineProperty(w, 'loadedCategories', {
        configurable: true, get() { return S.loadedCategories; }, set(v) { S.loadedCategories = v; }
    });
})(window);
