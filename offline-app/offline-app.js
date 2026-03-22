(function (root) {
    'use strict';

    const offlinePayload = (root.llToolsOfflineData && typeof root.llToolsOfflineData === 'object')
        ? root.llToolsOfflineData
        : {};
    const flashcards = (offlinePayload.flashcards && typeof offlinePayload.flashcards === 'object')
        ? offlinePayload.flashcards
        : {};
    const messages = (offlinePayload.messages && typeof offlinePayload.messages === 'object')
        ? offlinePayload.messages
        : {};
    const app = (offlinePayload.app && typeof offlinePayload.app === 'object')
        ? offlinePayload.app
        : {};

    root.llToolsFlashcardsData = Object.assign({
        runtimeMode: 'offline',
        plugin_dir: './plugin/',
        mode: 'random',
        quiz_mode: 'practice',
        ajaxurl: '',
        ajaxNonce: '',
        isUserLoggedIn: false,
        userStudyNonce: '',
        wordsetFallback: false,
        wordsetIds: [],
        categories: [],
        categoriesPreselected: false,
        firstCategoryData: [],
        firstCategoryName: '',
        imageSize: 'small',
        maxOptionsOverride: 9,
        userStudyState: {
            wordset_id: 0,
            category_ids: [],
            starred_word_ids: [],
            star_mode: 'normal',
            fast_transitions: false
        },
        availableModes: ['practice', 'learning'],
        offlineCategoryData: {}
    }, flashcards);

    root.llToolsFlashcardsMessages = Object.assign({}, messages);

    if (typeof document !== 'undefined') {
        document.documentElement.classList.add('ll-tools-offline-runtime');
        if (document.title && app.title) {
            document.title = String(app.title);
        }
        const title = document.querySelector('.ll-offline-app-title');
        if (title && app.title) {
            title.textContent = String(app.title);
        }
        const wordset = document.querySelector('.ll-offline-app-wordset');
        if (wordset && app.wordsetName) {
            wordset.textContent = String(app.wordsetName);
        }
        const rootEl = document.getElementById('ll-tools-flashcard-container');
        if (rootEl) {
            rootEl.setAttribute('data-wordset', String(root.llToolsFlashcardsData.wordset || ''));
            rootEl.setAttribute('data-wordset-fallback', root.llToolsFlashcardsData.wordsetFallback ? '1' : '0');
        }
    }
})(window);
