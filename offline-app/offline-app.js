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
    const documentRef = typeof document !== 'undefined' ? document : null;
    const launcherMessages = {
        selectionLabel: String(messages.offlineSelectCategories || 'Select categories to study together'),
        noCategories: String(messages.offlineNoCategories || 'No categories are available in this offline app.'),
        clearSelection: String(messages.offlineClearSelection || 'Clear Selection'),
        modePractice: String(messages.offlineModePractice || 'Practice'),
        modeLearning: String(messages.offlineModeLearning || 'Learn'),
        practiceSelected: String(messages.offlinePracticeSelected || 'Practice Selected'),
        learningSelected: String(messages.offlineLearningSelected || 'Learn Selected'),
        selectCategory: String(messages.offlineSelectCategory || 'Select category: %s'),
        learningUnavailable: String(messages.offlineLearningUnavailable || 'Learning mode is not available for this selection.'),
        noCategoriesSelected: String(messages.noCategoriesSelected || 'Select at least one category.'),
        somethingWentWrong: String(messages.somethingWentWrong || 'Something went wrong')
    };

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

    function normalizeCategory(category) {
        const source = (category && typeof category === 'object') ? category : {};
        return {
            id: parseInt(source.id, 10) || 0,
            slug: String(source.slug || ''),
            name: String(source.name || ''),
            translation: String(source.translation || ''),
            word_count: Math.max(0, parseInt(source.word_count, 10) || 0),
            learning_supported: !!source.learning_supported
        };
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatMessage(template, replacements) {
        let output = String(template || '');
        if (!Array.isArray(replacements) || !replacements.length) {
            return output;
        }
        replacements.forEach(function (value) {
            output = output.replace('%s', String(value));
        });
        return output;
    }

    function shuffleRows(rows) {
        const copy = Array.isArray(rows) ? rows.slice() : [];
        for (let index = copy.length - 1; index > 0; index -= 1) {
            const nextIndex = Math.floor(Math.random() * (index + 1));
            const current = copy[index];
            copy[index] = copy[nextIndex];
            copy[nextIndex] = current;
        }
        return copy;
    }

    function showAlert(message) {
        if (typeof root.alert === 'function') {
            root.alert(message);
            return;
        }
        if (root.console && typeof root.console.warn === 'function') {
            root.console.warn(message);
        }
    }

    function resetLaunchUi() {
        if (!documentRef) {
            return;
        }
        const popup = documentRef.getElementById('ll-tools-flashcard-popup');
        const quizPopup = documentRef.getElementById('ll-tools-flashcard-quiz-popup');
        if (popup) {
            popup.style.display = 'none';
        }
        if (quizPopup) {
            quizPopup.style.display = 'none';
        }
        if (documentRef.body) {
            documentRef.body.classList.remove('ll-tools-flashcard-open');
        }
    }

    function launchOfflineSelection(categoryIds, mode, categories, offlineCategoryData) {
        const normalizedMode = mode === 'learning' ? 'learning' : 'practice';
        const wantedLookup = {};
        const wantedIds = Array.isArray(categoryIds) ? categoryIds : [];
        wantedIds.forEach(function (categoryId) {
            const normalizedId = parseInt(categoryId, 10) || 0;
            if (normalizedId > 0) {
                wantedLookup[normalizedId] = true;
            }
        });

        const selectedCategories = categories.filter(function (category) {
            return !!wantedLookup[category.id];
        });

        if (!selectedCategories.length) {
            showAlert(launcherMessages.noCategoriesSelected);
            return;
        }

        if (normalizedMode === 'learning') {
            const hasUnsupportedLearning = selectedCategories.some(function (category) {
                return !category.learning_supported;
            });
            if (hasUnsupportedLearning) {
                showAlert(launcherMessages.learningUnavailable);
                return;
            }
        }

        const firstCategory = selectedCategories[0];
        const firstRows = shuffleRows(offlineCategoryData[firstCategory.name]);
        const flashData = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData === 'object')
            ? root.llToolsFlashcardsData
            : {};
        const selectedNames = selectedCategories.map(function (category) {
            return category.name;
        }).filter(Boolean);
        const selectedIds = selectedCategories.map(function (category) {
            return category.id;
        }).filter(function (categoryId) {
            return categoryId > 0;
        });

        flashData.runtimeMode = 'offline';
        flashData.categories = selectedCategories.slice();
        flashData.categoriesPreselected = true;
        flashData.firstCategoryName = String(firstCategory.name || '');
        flashData.firstCategoryData = firstRows;
        flashData.quiz_mode = normalizedMode;
        flashData.availableModes = ['practice', 'learning'];
        flashData.offlineCategoryData = offlineCategoryData;
        flashData.userStudyState = flashData.userStudyState || {};
        flashData.userStudyState.wordset_id = Array.isArray(flashData.wordsetIds) && flashData.wordsetIds.length
            ? (parseInt(flashData.wordsetIds[0], 10) || 0)
            : 0;
        flashData.userStudyState.category_ids = selectedIds.slice();
        flashData.userStudyState.starred_word_ids = [];
        flashData.userStudyState.star_mode = 'normal';
        flashData.userStudyState.fast_transitions = false;
        root.llToolsFlashcardsData = flashData;

        const popup = documentRef ? documentRef.getElementById('ll-tools-flashcard-popup') : null;
        const quizPopup = documentRef ? documentRef.getElementById('ll-tools-flashcard-quiz-popup') : null;
        if (popup) {
            popup.style.display = '';
        }
        if (quizPopup) {
            quizPopup.style.display = '';
        }
        if (documentRef && documentRef.body) {
            documentRef.body.classList.add('ll-tools-flashcard-open');
        }

        if (typeof root.initFlashcardWidget !== 'function') {
            resetLaunchUi();
            showAlert(launcherMessages.somethingWentWrong);
            return;
        }

        let launchResult = null;
        try {
            launchResult = root.initFlashcardWidget(selectedNames, normalizedMode);
        } catch (error) {
            resetLaunchUi();
            if (root.console && typeof root.console.error === 'function') {
                root.console.error('Offline flashcard launch failed.', error);
            }
            showAlert(launcherMessages.somethingWentWrong);
            return;
        }
        if (launchResult && typeof launchResult.catch === 'function') {
            launchResult.catch(function (error) {
                resetLaunchUi();
                if (root.console && typeof root.console.error === 'function') {
                    root.console.error('Offline flashcard launch failed.', error);
                }
                showAlert(launcherMessages.somethingWentWrong);
            });
        }
    }

    function initOfflineLauncher() {
        if (!documentRef) {
            return;
        }

        const rootEl = documentRef.getElementById('ll-tools-flashcard-container');
        const launcherEl = documentRef.getElementById('ll-offline-launcher');
        const gridEl = documentRef.getElementById('ll-offline-category-grid');
        const emptyEl = documentRef.getElementById('ll-offline-category-empty');
        const selectionCountEl = documentRef.getElementById('ll-offline-selection-count');
        const selectionTextEl = documentRef.getElementById('ll-offline-selection-text');
        const clearSelectionButton = documentRef.getElementById('ll-offline-clear-selection');
        const learningSelectedButton = documentRef.getElementById('ll-offline-launch-learning-selected');
        const practiceSelectedButton = documentRef.getElementById('ll-offline-launch-practice-selected');
        if (!rootEl || !launcherEl || !gridEl || !emptyEl || !clearSelectionButton || !learningSelectedButton || !practiceSelectedButton) {
            return;
        }

        const flashData = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData === 'object')
            ? root.llToolsFlashcardsData
            : {};
        const categories = Array.isArray(flashData.categories)
            ? flashData.categories.map(normalizeCategory).filter(function (category) {
                return category.id > 0 && category.name !== '';
            })
            : [];
        const offlineCategoryData = (flashData.offlineCategoryData && typeof flashData.offlineCategoryData === 'object')
            ? flashData.offlineCategoryData
            : {};
        let selectedIds = [];

        function getSelectedCategories() {
            const selectedLookup = {};
            selectedIds.forEach(function (categoryId) {
                selectedLookup[categoryId] = true;
            });
            return categories.filter(function (category) {
                return !!selectedLookup[category.id];
            });
        }

        function syncSelectionUi() {
            const selectedCategories = getSelectedCategories();
            const selectedCount = selectedCategories.length;
            const learningAllowed = selectedCount > 0 && !selectedCategories.some(function (category) {
                return !category.learning_supported;
            });

            if (selectionTextEl) {
                selectionTextEl.textContent = launcherMessages.selectionLabel;
            }
            if (selectionCountEl) {
                selectionCountEl.textContent = String(selectedCount);
                selectionCountEl.hidden = selectedCount < 1;
            }
            clearSelectionButton.hidden = selectedCount < 1;
            clearSelectionButton.textContent = launcherMessages.clearSelection;
            practiceSelectedButton.textContent = launcherMessages.practiceSelected;
            practiceSelectedButton.disabled = selectedCount < 1;
            learningSelectedButton.textContent = launcherMessages.learningSelected;
            learningSelectedButton.disabled = !learningAllowed;
        }

        function renderCategoryCards() {
            if (!categories.length) {
                emptyEl.hidden = false;
                emptyEl.textContent = launcherMessages.noCategories;
                gridEl.innerHTML = '';
                syncSelectionUi();
                return;
            }

            emptyEl.hidden = true;
            gridEl.innerHTML = categories.map(function (category) {
                const translation = category.translation && category.translation !== category.name
                    ? '<p class="ll-offline-category-card__translation">' + escapeHtml(category.translation) + '</p>'
                    : '';
                const selectLabel = formatMessage(launcherMessages.selectCategory, [category.name]);
                const learnDisabled = category.learning_supported ? '' : ' disabled';

                return ''
                    + '<article class="ll-offline-category-card" data-category-id="' + category.id + '">'
                    + '  <div class="ll-offline-category-card__header">'
                    + '    <input class="ll-offline-category-card__toggle" type="checkbox" data-ll-offline-category-select data-cat-id="' + category.id + '" aria-label="' + escapeHtml(selectLabel) + '">'
                    + '    <div class="ll-offline-category-card__header-main">'
                    + '      <h2 class="ll-offline-category-card__title">' + escapeHtml(category.name) + '</h2>'
                    +        translation
                    + '    </div>'
                    + '    <span class="ll-offline-category-card__count">' + category.word_count + '</span>'
                    + '  </div>'
                    + '  <div class="ll-offline-category-card__actions">'
                    + '    <button class="ll-offline-category-card__action ll-offline-category-card__action--learning" data-ll-offline-category-mode data-mode="learning" data-cat-id="' + category.id + '" type="button"' + learnDisabled + '>' + escapeHtml(launcherMessages.modeLearning) + '</button>'
                    + '    <button class="ll-offline-category-card__action" data-ll-offline-category-mode data-mode="practice" data-cat-id="' + category.id + '" type="button">' + escapeHtml(launcherMessages.modePractice) + '</button>'
                    + '  </div>'
                    + '</article>';
            }).join('');
            syncSelectionUi();
        }

        launcherEl.addEventListener('change', function (event) {
            const target = event.target;
            if (!target || !target.matches('[data-ll-offline-category-select]')) {
                return;
            }
            const categoryId = parseInt(target.getAttribute('data-cat-id') || '', 10) || 0;
            if (!categoryId) {
                return;
            }
            if (target.checked) {
                if (selectedIds.indexOf(categoryId) === -1) {
                    selectedIds.push(categoryId);
                }
            } else {
                selectedIds = selectedIds.filter(function (selectedId) {
                    return selectedId !== categoryId;
                });
            }
            syncSelectionUi();
        });

        launcherEl.addEventListener('click', function (event) {
            const target = event.target;
            if (!target || !(target instanceof HTMLElement)) {
                return;
            }

            const categoryModeButton = target.closest('[data-ll-offline-category-mode]');
            if (categoryModeButton) {
                const categoryId = parseInt(categoryModeButton.getAttribute('data-cat-id') || '', 10) || 0;
                if (!categoryId) {
                    return;
                }
                launchOfflineSelection([categoryId], categoryModeButton.getAttribute('data-mode') || 'practice', categories, offlineCategoryData);
                return;
            }

            const selectedModeButton = target.closest('[data-ll-offline-launch-selected]');
            if (selectedModeButton) {
                launchOfflineSelection(selectedIds.slice(), selectedModeButton.getAttribute('data-mode') || 'practice', categories, offlineCategoryData);
                return;
            }

            if (target.closest('#ll-offline-clear-selection')) {
                selectedIds = [];
                Array.prototype.forEach.call(launcherEl.querySelectorAll('[data-ll-offline-category-select]'), function (input) {
                    input.checked = false;
                });
                syncSelectionUi();
            }
        });

        renderCategoryCards();
    }

    if (documentRef) {
        documentRef.documentElement.classList.add('ll-tools-offline-runtime');
        if (documentRef.title && app.title) {
            documentRef.title = String(app.title);
        }
        const title = documentRef.querySelector('.ll-offline-app-title');
        if (title && app.title) {
            title.textContent = String(app.title);
        }
        const wordset = documentRef.querySelector('.ll-offline-app-wordset');
        if (wordset && app.wordsetName) {
            wordset.textContent = String(app.wordsetName);
        }
        const rootEl = documentRef.getElementById('ll-tools-flashcard-container');
        if (rootEl) {
            rootEl.setAttribute('data-wordset', String(root.llToolsFlashcardsData.wordset || ''));
            rootEl.setAttribute('data-wordset-fallback', root.llToolsFlashcardsData.wordsetFallback ? '1' : '0');
        }
        initOfflineLauncher();
    }
})(window);
