(function (root) {
    'use strict';

    const $ = root.jQuery;
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
    const gamesPayload = (offlinePayload.games && typeof offlinePayload.games === 'object')
        ? offlinePayload.games
        : {};
    const launcherConfig = (app.launcher && typeof app.launcher === 'object')
        ? app.launcher
        : {};
    const syncConfig = Object.assign(
        {},
        (flashcards.offlineSync && typeof flashcards.offlineSync === 'object') ? flashcards.offlineSync : {},
        (app.sync && typeof app.sync === 'object') ? app.sync : {}
    );
    const syncConfigMessages = (syncConfig.messages && typeof syncConfig.messages === 'object')
        ? syncConfig.messages
        : {};
    const modeUi = (flashcards.modeUi && typeof flashcards.modeUi === 'object')
        ? flashcards.modeUi
        : {};
    const documentRef = typeof document !== 'undefined' ? document : null;

    const launcherMessages = {
        selectionLabel: String(messages.offlineSelectCategories || 'Select categories to study together'),
        selectionWords: String(messages.offlineSelectionWords || '%d words'),
        noCategories: String(messages.offlineNoCategories || 'No categories are available in this offline app.'),
        clearSelection: String(messages.offlineClearSelection || 'Clear Selection'),
        selectAll: String(messages.offlineSelectAll || 'Select All'),
        deselectAll: String(messages.offlineDeselectAll || 'Deselect All'),
        modePractice: String(messages.offlineModePractice || 'Practice'),
        modeLearning: String(messages.offlineModeLearning || 'Learn'),
        modeListening: String(messages.offlineModeListening || 'Listen'),
        modeGender: String(messages.offlineModeGender || 'Gender'),
        modeSelfCheck: String(messages.offlineModeSelfCheck || 'Self check'),
        selectCategory: String(messages.offlineSelectCategory || 'Select category: %s'),
        modeCategoryLabel: String(messages.offlineModeCategoryLabel || '%1$s: %2$s'),
        learningUnavailable: String(messages.offlineLearningUnavailable || 'Learning mode is not available for this selection.'),
        genderUnavailable: String(messages.offlineGenderUnavailable || 'Gender mode is not available for this selection.'),
        noCategoriesSelected: String(messages.noCategoriesSelected || 'Select at least one category.'),
        somethingWentWrong: String(messages.somethingWentWrong || 'Something went wrong')
    };
    const syncMessages = {
        localOnlyLabel: String(syncConfigMessages.localOnlyLabel || 'Local progress only'),
        connectedAsLabel: String(syncConfigMessages.connectedAsLabel || 'Connected as %s'),
        connectButton: String(syncConfigMessages.connectButton || 'Connect account'),
        disconnectButton: String(syncConfigMessages.disconnectButton || 'Disconnect'),
        syncNowButton: String(syncConfigMessages.syncNowButton || 'Sync now'),
        syncPendingLabel: String(syncConfigMessages.syncPendingLabel || '%d pending'),
        syncIdleLabel: String(syncConfigMessages.syncIdleLabel || 'All caught up'),
        syncFailedLabel: String(syncConfigMessages.syncFailedLabel || 'Sync failed. Your local progress is still saved.'),
        syncFormTitle: String(syncConfigMessages.syncFormTitle || 'Connect to Sync'),
        syncIdentifierLabel: String(syncConfigMessages.syncIdentifierLabel || 'Username or email'),
        syncPasswordLabel: String(syncConfigMessages.syncPasswordLabel || 'Password'),
        syncSubmitButton: String(syncConfigMessages.syncSubmitButton || 'Sign in'),
        syncCancelButton: String(syncConfigMessages.syncCancelButton || 'Cancel'),
        syncSignedOutLabel: String(syncConfigMessages.syncSignedOutLabel || 'Disconnected. The app will keep storing progress locally.'),
        syncInProgressLabel: String(syncConfigMessages.syncInProgressLabel || 'Syncing...'),
        showPasswordLabel: String(syncConfigMessages.showPasswordLabel || 'Show password'),
        hidePasswordLabel: String(syncConfigMessages.hidePasswordLabel || 'Hide password')
    };
    const MODE_ORDER = ['learning', 'practice', 'listening', 'gender', 'self-check'];

    function getProgressTracker() {
        return root.LLFlashcards && root.LLFlashcards.ProgressTracker
            ? root.LLFlashcards.ProgressTracker
            : null;
    }

    function toInt(value) {
        const parsed = parseInt(value, 10);
        return parsed > 0 ? parsed : 0;
    }

    function getOfflineAppStateStorageKey() {
        const wordsetId = toInt(
            (flashcards.userStudyState && flashcards.userStudyState.wordset_id)
            || (Array.isArray(flashcards.wordsetIds) ? flashcards.wordsetIds[0] : 0)
        );
        return 'lltools_offline_app_state_v1::wordset:' + String(wordsetId || 0);
    }

    function loadOfflineAppState() {
        if (!root.localStorage) {
            return { selected_category_ids: [] };
        }
        try {
            const raw = root.localStorage.getItem(getOfflineAppStateStorageKey());
            if (!raw) {
                return { selected_category_ids: [] };
            }
            const decoded = JSON.parse(raw);
            if (!decoded || typeof decoded !== 'object') {
                return { selected_category_ids: [] };
            }
            return {
                selected_category_ids: Array.isArray(decoded.selected_category_ids)
                    ? decoded.selected_category_ids.map(toInt).filter(Boolean)
                    : []
            };
        } catch (_) {
            return { selected_category_ids: [] };
        }
    }

    function saveOfflineAppState(nextState) {
        if (!root.localStorage) {
            return false;
        }
        const state = (nextState && typeof nextState === 'object') ? nextState : {};
        const payload = {
            selected_category_ids: Array.isArray(state.selected_category_ids)
                ? state.selected_category_ids.map(toInt).filter(Boolean)
                : []
        };
        try {
            root.localStorage.setItem(getOfflineAppStateStorageKey(), JSON.stringify(payload));
            return true;
        } catch (_) {
            return false;
        }
    }

    function persistSelectedCategoryIds(categoryIds) {
        const normalized = Array.isArray(categoryIds) ? categoryIds.map(toInt).filter(Boolean) : [];
        saveOfflineAppState({ selected_category_ids: normalized });
        if (root.llToolsFlashcardsData && root.llToolsFlashcardsData.userStudyState) {
            root.llToolsFlashcardsData.userStudyState.category_ids = normalized.slice();
        }
    }

    function applyOfflineStudyState(state) {
        const next = (state && typeof state === 'object') ? state : {};
        if (root.llToolsFlashcardsData && root.llToolsFlashcardsData.userStudyState) {
            root.llToolsFlashcardsData.userStudyState.wordset_id = toInt(next.wordset_id || root.llToolsFlashcardsData.userStudyState.wordset_id);
            root.llToolsFlashcardsData.userStudyState.category_ids = Array.isArray(next.category_ids)
                ? next.category_ids.map(toInt).filter(Boolean)
                : (root.llToolsFlashcardsData.userStudyState.category_ids || []);
            root.llToolsFlashcardsData.userStudyState.starred_word_ids = Array.isArray(next.starred_word_ids)
                ? next.starred_word_ids.map(toInt).filter(Boolean)
                : (root.llToolsFlashcardsData.userStudyState.starred_word_ids || []);
            root.llToolsFlashcardsData.userStudyState.star_mode = String(next.star_mode || root.llToolsFlashcardsData.userStudyState.star_mode || 'normal');
            root.llToolsFlashcardsData.userStudyState.fast_transitions = !!(next.fast_transitions ?? root.llToolsFlashcardsData.userStudyState.fast_transitions);
        }
        persistSelectedCategoryIds((root.llToolsFlashcardsData && root.llToolsFlashcardsData.userStudyState && root.llToolsFlashcardsData.userStudyState.category_ids) || []);

        if (documentRef && typeof documentRef.dispatchEvent === 'function') {
            documentRef.dispatchEvent(new CustomEvent('lltools:offline-app-state-updated', {
                detail: {
                    state: (root.llToolsFlashcardsData && root.llToolsFlashcardsData.userStudyState) || {}
                }
            }));
        }
    }

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
        availableModes: MODE_ORDER.slice(),
        offlineCategoryData: {}
    }, flashcards);

    const storedAppState = loadOfflineAppState();
    if (root.llToolsFlashcardsData && root.llToolsFlashcardsData.userStudyState
        && Array.isArray(storedAppState.selected_category_ids)
        && storedAppState.selected_category_ids.length
    ) {
        root.llToolsFlashcardsData.userStudyState.category_ids = storedAppState.selected_category_ids.slice();
    }

    root.llToolsFlashcardsMessages = Object.assign({}, messages);

    function normalizePreviewItem(previewItem) {
        const source = (previewItem && typeof previewItem === 'object') ? previewItem : {};
        const type = String(source.type || 'text') === 'image' ? 'image' : 'text';

        if (type === 'image') {
            return {
                type: 'image',
                url: String(source.url || ''),
                alt: String(source.alt || ''),
                ratio: String(source.ratio || ''),
                width: Math.max(0, parseInt(source.width, 10) || 0),
                height: Math.max(0, parseInt(source.height, 10) || 0)
            };
        }

        return {
            type: 'text',
            label: String(source.label || '')
        };
    }

    function normalizeCategory(category) {
        const source = (category && typeof category === 'object') ? category : {};
        const preview = Array.isArray(source.preview)
            ? source.preview.map(normalizePreviewItem).filter(function (item) {
                return (item.type === 'image' && item.url !== '') || (item.type === 'text' && item.label !== '');
            })
            : [];

        return {
            id: parseInt(source.id, 10) || 0,
            slug: String(source.slug || ''),
            name: String(source.name || ''),
            translation: String(source.translation || ''),
            mode: String(source.mode || ''),
            option_type: String(source.option_type || ''),
            prompt_type: String(source.prompt_type || ''),
            use_titles: !!source.use_titles,
            word_count: Math.max(0, parseInt(source.word_count || source.count, 10) || 0),
            learning_supported: !!source.learning_supported,
            gender_supported: !!source.gender_supported,
            aspect_bucket: String(source.aspect_bucket || 'no-image'),
            preview_aspect_ratio: String(source.preview_aspect_ratio || ''),
            preview: preview,
            preview_limit: Math.max(1, parseInt(source.preview_limit, 10) || 2),
            has_images: !!source.has_images
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

        replacements.forEach(function (value, index) {
            const replacement = String(value);
            output = output.replace(new RegExp('%' + (index + 1) + '\\$s', 'g'), replacement);
            output = output.replace(new RegExp('%' + (index + 1) + '\\$d', 'g'), replacement);
            output = output.replace('%s', replacement);
            output = output.replace('%d', replacement);
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

    function getOfflineWordsetId() {
        const flashData = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData === 'object')
            ? root.llToolsFlashcardsData
            : {};
        const userState = (flashData.userStudyState && typeof flashData.userStudyState === 'object')
            ? flashData.userStudyState
            : {};
        return toInt(
            userState.wordset_id
            || flashData.genderWordsetId
            || (Array.isArray(flashData.wordsetIds) ? flashData.wordsetIds[0] : 0)
        );
    }

    function collectOfflineWordIds() {
        const flashData = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData === 'object')
            ? root.llToolsFlashcardsData
            : {};
        const offlineCategoryData = (flashData.offlineCategoryData && typeof flashData.offlineCategoryData === 'object')
            ? flashData.offlineCategoryData
            : {};
        const ids = [];
        const seen = {};

        Object.keys(offlineCategoryData).forEach(function (categoryName) {
            const rows = Array.isArray(offlineCategoryData[categoryName]) ? offlineCategoryData[categoryName] : [];
            rows.forEach(function (word) {
                const wordId = toInt(word && word.id);
                if (!wordId || seen[wordId]) {
                    return;
                }
                seen[wordId] = true;
                ids.push(wordId);
            });
        });

        return ids;
    }

    function buildOfflineSyncApi() {
        const getTracker = function () {
            return root.LLFlashcards && root.LLFlashcards.ProgressTracker
                ? root.LLFlashcards.ProgressTracker
                : null;
        };
        const getPrefsSync = function () {
            return root.LLFlashcards && root.LLFlashcards.OfflineStudyPrefsSync
                ? root.LLFlashcards.OfflineStudyPrefsSync
                : null;
        };

        return {
            configureAuth: function (auth) {
                const tracker = getTracker();
                const prefsSync = getPrefsSync();
                if (tracker && typeof tracker.setAuthContext === 'function') {
                    tracker.setAuthContext(auth || {});
                }

                const syncProgress = tracker && typeof tracker.syncFromServer === 'function'
                    ? tracker.syncFromServer({
                        wordsetId: getOfflineWordsetId(),
                        wordIds: collectOfflineWordIds()
                    })
                    : Promise.resolve({ skipped: true });

                return Promise.resolve(syncProgress).then(function () {
                    if (prefsSync && typeof prefsSync.flush === 'function') {
                        return prefsSync.flush(true);
                    }
                    return { skipped: true };
                }).then(function () {
                    const progressState = tracker && typeof tracker.getSyncState === 'function'
                        ? tracker.getSyncState()
                        : {};
                    const prefsState = prefsSync && typeof prefsSync.getStatus === 'function'
                        ? prefsSync.getStatus()
                        : {};
                    return {
                        progress: progressState,
                        prefs: prefsState
                    };
                });
            },
            flush: function () {
                const tracker = getTracker();
                const prefsSync = getPrefsSync();
                const progressFlush = tracker && typeof tracker.flush === 'function'
                    ? tracker.flush({ wordsetId: getOfflineWordsetId() })
                    : Promise.resolve({ skipped: true });

                return Promise.resolve(progressFlush).then(function (progress) {
                    if (prefsSync && typeof prefsSync.flush === 'function') {
                        return Promise.resolve(prefsSync.flush(false)).then(function (prefs) {
                            return { progress: progress, prefs: prefs };
                        });
                    }
                    return { progress: progress, prefs: { skipped: true } };
                });
            },
            getState: function () {
                const tracker = getTracker();
                const prefsSync = getPrefsSync();
                return {
                    progress: tracker && typeof tracker.getSyncState === 'function'
                        ? tracker.getSyncState()
                        : {},
                    prefs: prefsSync && typeof prefsSync.getStatus === 'function'
                        ? prefsSync.getStatus()
                        : {}
                };
            },
            collectWordIds: collectOfflineWordIds
        };
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

    function getModeConfig(mode) {
        const fallbacks = {
            learning: { icon: '🎓', svg: '' },
            practice: { icon: '❓', svg: '' },
            listening: { icon: '🎧', svg: '' },
            gender: { icon: '⚥', svg: '' },
            'self-check': { icon: '✔✖', svg: '' }
        };
        const fallback = fallbacks[mode] || fallbacks.practice;
        const source = (modeUi[mode] && typeof modeUi[mode] === 'object') ? modeUi[mode] : {};

        return {
            icon: String(source.icon || fallback.icon || ''),
            svg: String(source.svg || fallback.svg || ''),
            className: String(source.className || (mode + '-mode'))
        };
    }

    function normalizeMode(mode) {
        const normalized = String(mode || '').trim().toLowerCase();
        return MODE_ORDER.indexOf(normalized) !== -1 ? normalized : 'practice';
    }

    function getModeLabel(mode) {
        const normalizedMode = normalizeMode(mode);
        const labelKeyMap = {
            learning: 'modeLearning',
            practice: 'modePractice',
            listening: 'modeListening',
            gender: 'modeGender',
            'self-check': 'modeSelfCheck'
        };
        const launcherLabel = launcherMessages[labelKeyMap[normalizedMode] || 'modePractice'];
        if (launcherLabel) {
            return launcherLabel;
        }
        const modeConfig = (modeUi[normalizedMode] && typeof modeUi[normalizedMode] === 'object')
            ? modeUi[normalizedMode]
            : {};
        const fallback = normalizedMode === 'self-check'
            ? 'Self check'
            : (normalizedMode.charAt(0).toUpperCase() + normalizedMode.slice(1));
        return String(modeConfig.resultsButtonText || modeConfig.switchLabel || fallback);
    }

    function getConfiguredModes(flashData) {
        const raw = (flashData && Array.isArray(flashData.availableModes)) ? flashData.availableModes : MODE_ORDER;
        const normalized = [];
        raw.forEach(function (mode) {
            const nextMode = normalizeMode(mode);
            if (normalized.indexOf(nextMode) === -1) {
                normalized.push(nextMode);
            }
        });
        return normalized.length ? normalized : MODE_ORDER.slice();
    }

    function isModeSupportedForCategory(mode, category, flashData) {
        const normalizedMode = normalizeMode(mode);
        const sourceCategory = (category && typeof category === 'object') ? category : {};
        const data = (flashData && typeof flashData === 'object') ? flashData : {};
        if (normalizedMode === 'learning') {
            return !!sourceCategory.learning_supported;
        }
        if (normalizedMode === 'gender') {
            return !!data.genderEnabled && !!sourceCategory.gender_supported;
        }
        return true;
    }

    function isModeSupportedForSelection(mode, selectedCategories, flashData) {
        const categories = Array.isArray(selectedCategories) ? selectedCategories : [];
        if (!categories.length) {
            return false;
        }
        return categories.every(function (category) {
            return isModeSupportedForCategory(mode, category, flashData);
        });
    }

    function getCardModesForCategory(category, flashData) {
        const configuredModes = getConfiguredModes(flashData);
        const ordered = ['learning', 'practice', 'listening', 'gender', 'self-check'];
        return ordered.filter(function (mode) {
            if (configuredModes.indexOf(mode) === -1) {
                return false;
            }
            if (mode === 'gender') {
                return isModeSupportedForCategory('gender', category, flashData);
            }
            return true;
        });
    }

    function getSelectionModes(categories, flashData) {
        const configuredModes = getConfiguredModes(flashData);
        const hasGender = (Array.isArray(categories) ? categories : []).some(function (category) {
            return !!(category && category.gender_supported);
        });
        return ['learning', 'practice', 'listening', 'gender', 'self-check'].filter(function (mode) {
            if (configuredModes.indexOf(mode) === -1) {
                return false;
            }
            if (mode === 'gender') {
                return !!flashData.genderEnabled && hasGender;
            }
            return true;
        });
    }

    function getModeIconMarkup(mode, className) {
        const cfg = getModeConfig(mode);
        const safeClassName = escapeHtml(String(className || 'll-offline-mode-icon'));

        if (cfg.svg) {
            return '<span class="' + safeClassName + '" aria-hidden="true">' + cfg.svg + '</span>';
        }

        return '<span class="' + safeClassName + '" aria-hidden="true">' + escapeHtml(cfg.icon || '') + '</span>';
    }

    function getLauncherCategories(flashData) {
        const normalizedQuizCategories = Array.isArray(flashData.categories)
            ? flashData.categories.map(normalizeCategory).filter(function (category) {
                return category.name !== '';
            })
            : [];
        const quizById = {};
        const quizByName = {};

        normalizedQuizCategories.forEach(function (category) {
            if (category.id > 0) {
                quizById[category.id] = category;
            }
            if (category.name !== '') {
                quizByName[category.name] = category;
            }
        });

        const launcherSource = Array.isArray(launcherConfig.categories) && launcherConfig.categories.length
            ? launcherConfig.categories
            : normalizedQuizCategories;

        return launcherSource.map(function (category) {
            const normalizedLauncherCategory = normalizeCategory(category);
            const fallbackCategory = (normalizedLauncherCategory.id > 0 && quizById[normalizedLauncherCategory.id])
                ? quizById[normalizedLauncherCategory.id]
                : (quizByName[normalizedLauncherCategory.name] || {});
            const mergedCategory = Object.assign({}, fallbackCategory, normalizedLauncherCategory);

            if ((!Array.isArray(mergedCategory.preview) || !mergedCategory.preview.length) && fallbackCategory.preview) {
                mergedCategory.preview = Array.isArray(fallbackCategory.preview)
                    ? fallbackCategory.preview.slice()
                    : [];
            }

            if (!mergedCategory.word_count) {
                mergedCategory.word_count = Math.max(0, parseInt(fallbackCategory.word_count, 10) || 0);
            }

            return normalizeCategory(mergedCategory);
        }).filter(function (category) {
            return category.id > 0 && category.name !== '';
        });
    }

    function renderPreviewMarkup(category) {
        const previewItems = Array.isArray(category.preview) ? category.preview.slice(0, category.preview_limit || 2) : [];
        const markup = [];
        const limit = Math.max(1, parseInt(category.preview_limit, 10) || 2);

        previewItems.forEach(function (previewItem) {
            if (previewItem.type === 'image' && previewItem.url) {
                const ratioStyle = previewItem.ratio
                    ? ' style="aspect-ratio: ' + escapeHtml(previewItem.ratio) + ' !important;"'
                    : '';
                const widthAttr = previewItem.width > 0 ? ' width="' + previewItem.width + '"' : '';
                const heightAttr = previewItem.height > 0 ? ' height="' + previewItem.height + '"' : '';
                markup.push(
                    '<span class="ll-wordset-preview-item ll-wordset-preview-item--image"' + ratioStyle + '>' +
                        '<img src="' + escapeHtml(previewItem.url) + '" alt="' + escapeHtml(previewItem.alt || '') + '"' + widthAttr + heightAttr + ' loading="lazy" decoding="async">' +
                    '</span>'
                );
                return;
            }

            if (previewItem.type === 'text' && previewItem.label) {
                markup.push(
                    '<span class="ll-wordset-preview-item ll-wordset-preview-item--text">' +
                        '<span class="ll-wordset-preview-text" dir="auto">' + escapeHtml(previewItem.label) + '</span>' +
                    '</span>'
                );
            }
        });

        while (markup.length < limit) {
            markup.push('<span class="ll-wordset-preview-item ll-wordset-preview-item--empty" aria-hidden="true"></span>');
        }

        return markup.join('');
    }

    function buildSelectionActionMarkup(mode) {
        const label = getModeLabel(mode);
        return getModeIconMarkup(mode, 'll-vocab-lesson-mode-icon') +
            '<span class="ll-vocab-lesson-mode-label">' + escapeHtml(label) + '</span>';
    }

    function buildCategoryActionMarkup(mode, category, flashData) {
        const label = getModeLabel(mode);
        const ariaLabel = formatMessage(launcherMessages.modeCategoryLabel, [label, category.name]);
        const disabled = !isModeSupportedForCategory(mode, category, flashData) ? ' disabled' : '';

        return '<button class="ll-wordset-card__quiz-btn' +
                '" data-ll-offline-category-mode data-mode="' + mode + '" data-cat-id="' + category.id + '"' +
                ' type="button" aria-label="' + escapeHtml(ariaLabel) + '"' + disabled + '>' +
                getModeIconMarkup(mode, 'll-wordset-card__quiz-icon') +
            '</button>';
    }

    function launchOfflineSelection(categoryIds, mode, categories, offlineCategoryData) {
        const normalizedMode = normalizeMode(mode);
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

        if (normalizedMode === 'learning' && !isModeSupportedForSelection('learning', selectedCategories, root.llToolsFlashcardsData)) {
            showAlert(launcherMessages.learningUnavailable);
            return;
        }

        if (normalizedMode === 'gender' && !isModeSupportedForSelection('gender', selectedCategories, root.llToolsFlashcardsData)) {
            showAlert(launcherMessages.genderUnavailable);
            return;
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
        const prefs = (root.llToolsStudyPrefs && typeof root.llToolsStudyPrefs === 'object')
            ? root.llToolsStudyPrefs
            : {};
        const starredWordIds = Array.isArray(prefs.starredWordIds)
            ? prefs.starredWordIds.slice()
            : (Array.isArray(flashData.starredWordIds) ? flashData.starredWordIds.slice() : []);
        const starMode = String(prefs.starMode || prefs.star_mode || flashData.starMode || flashData.star_mode || 'normal');
        const fastTransitions = !!(prefs.fastTransitions ?? prefs.fast_transitions ?? flashData.fastTransitions ?? flashData.fast_transitions ?? false);

        flashData.runtimeMode = 'offline';
        flashData.categories = selectedCategories.slice();
        flashData.categoriesPreselected = true;
        flashData.firstCategoryName = String(firstCategory.name || '');
        flashData.firstCategoryData = firstRows;
        flashData.quiz_mode = normalizedMode;
        flashData.availableModes = getConfiguredModes(flashData);
        flashData.offlineCategoryData = offlineCategoryData;
        flashData.userStudyState = Object.assign({}, flashData.userStudyState || {}, {
            wordset_id: Array.isArray(flashData.wordsetIds) && flashData.wordsetIds.length
                ? (parseInt(flashData.wordsetIds[0], 10) || 0)
                : 0,
            category_ids: selectedIds.slice(),
            starred_word_ids: starredWordIds.slice(),
            star_mode: starMode,
            fast_transitions: fastTransitions
        });
        flashData.starredWordIds = starredWordIds.slice();
        flashData.starred_word_ids = starredWordIds.slice();
        flashData.starMode = starMode;
        flashData.star_mode = starMode;
        flashData.fastTransitions = fastTransitions;
        flashData.fast_transitions = fastTransitions;
        if (normalizedMode === 'gender') {
            flashData.genderLaunchSource = 'direct';
        }
        root.llToolsFlashcardsData = flashData;
        persistSelectedCategoryIds(selectedIds.slice());

        if (root.llToolsStudyPrefs && typeof root.llToolsStudyPrefs === 'object') {
            root.llToolsStudyPrefs.starredWordIds = starredWordIds.slice();
            root.llToolsStudyPrefs.starred_word_ids = starredWordIds.slice();
            root.llToolsStudyPrefs.starMode = starMode;
            root.llToolsStudyPrefs.star_mode = starMode;
            root.llToolsStudyPrefs.fastTransitions = fastTransitions;
            root.llToolsStudyPrefs.fast_transitions = fastTransitions;
        }

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

    function getOfflineGamesViewToggleButtons() {
        return documentRef
            ? Array.prototype.slice.call(documentRef.querySelectorAll('[data-ll-offline-view-toggle]'))
            : [];
    }

    function setOfflineActiveView(nextView) {
        if (!documentRef) {
            return;
        }

        const targetView = String(nextView || 'study');
        Array.prototype.forEach.call(documentRef.querySelectorAll('[data-ll-offline-view]'), function (section) {
            const viewName = String(section.getAttribute('data-ll-offline-view') || '');
            section.hidden = viewName !== targetView;
        });

        getOfflineGamesViewToggleButtons().forEach(function (button) {
            const viewName = String(button.getAttribute('data-target-view') || '');
            button.setAttribute('aria-pressed', viewName === targetView ? 'true' : 'false');
        });
    }

    function blobToBase64(blob) {
        return new Promise(function (resolve, reject) {
            if (!root.FileReader) {
                reject(new Error('file_reader_unavailable'));
                return;
            }

            const reader = new root.FileReader();
            reader.onerror = function () {
                reject(new Error('file_reader_failed'));
            };
            reader.onload = function () {
                const result = String(reader.result || '');
                const commaIndex = result.indexOf(',');
                resolve(commaIndex === -1 ? result : result.slice(commaIndex + 1));
            };
            reader.readAsDataURL(blob);
        });
    }

    function bytesToBase64(bytes) {
        if (!bytes || typeof bytes.length !== 'number') {
            return '';
        }

        let binary = '';
        const chunkSize = 0x2000;
        for (let offset = 0; offset < bytes.length; offset += chunkSize) {
            const chunk = bytes.subarray(offset, Math.min(bytes.length, offset + chunkSize));
            binary += String.fromCharCode.apply(null, Array.prototype.slice.call(chunk));
        }

        if (typeof root.btoa === 'function') {
            return root.btoa(binary);
        }

        throw new Error('base64_encoding_unavailable');
    }

    function decodeAudioDataCompat(audioContext, arrayBuffer) {
        return new Promise(function (resolve, reject) {
            let settled = false;
            function resolveOnce(value) {
                if (!settled) {
                    settled = true;
                    resolve(value);
                }
            }
            function rejectOnce(error) {
                if (!settled) {
                    settled = true;
                    reject(error);
                }
            }

            try {
                const promise = audioContext.decodeAudioData(
                    arrayBuffer,
                    resolveOnce,
                    rejectOnce
                );
                if (promise && typeof promise.then === 'function') {
                    promise.then(resolveOnce).catch(rejectOnce);
                }
            } catch (error) {
                rejectOnce(error);
            }
        });
    }

    function mixAudioBufferToMono(audioBuffer) {
        const frameCount = Math.max(0, Number(audioBuffer && audioBuffer.length) || 0);
        const channelCount = Math.max(1, Number(audioBuffer && audioBuffer.numberOfChannels) || 1);
        const mono = new Float32Array(frameCount);
        if (!frameCount) {
            return mono;
        }

        for (let channelIndex = 0; channelIndex < channelCount; channelIndex += 1) {
            const channelData = audioBuffer.getChannelData(channelIndex);
            for (let frameIndex = 0; frameIndex < frameCount; frameIndex += 1) {
                mono[frameIndex] += channelData[frameIndex] || 0;
            }
        }

        if (channelCount > 1) {
            for (let frameIndex = 0; frameIndex < frameCount; frameIndex += 1) {
                mono[frameIndex] /= channelCount;
            }
        }

        return mono;
    }

    function resampleFloat32Linear(samples, sourceSampleRate, targetSampleRate) {
        const sourceRate = Math.max(1, Number(sourceSampleRate) || 1);
        const targetRate = Math.max(1, Number(targetSampleRate) || 1);
        if (!(samples instanceof Float32Array) || !samples.length || sourceRate === targetRate) {
            return samples instanceof Float32Array ? samples : new Float32Array(0);
        }

        const targetLength = Math.max(1, Math.round(samples.length * (targetRate / sourceRate)));
        const output = new Float32Array(targetLength);
        const ratio = sourceRate / targetRate;

        for (let index = 0; index < targetLength; index += 1) {
            const sourcePosition = index * ratio;
            const leftIndex = Math.floor(sourcePosition);
            const rightIndex = Math.min(samples.length - 1, leftIndex + 1);
            const interpolation = sourcePosition - leftIndex;
            const leftSample = samples[leftIndex] || 0;
            const rightSample = samples[rightIndex] || 0;
            output[index] = (leftSample * (1 - interpolation)) + (rightSample * interpolation);
        }

        return output;
    }

    function float32ToPcm16Base64(samples) {
        const source = samples instanceof Float32Array ? samples : new Float32Array(0);
        const bytes = new Uint8Array(source.length * 2);
        const view = new DataView(bytes.buffer);

        for (let index = 0; index < source.length; index += 1) {
            const value = Math.max(-1, Math.min(1, Number(source[index]) || 0));
            const int16 = value < 0 ? Math.round(value * 0x8000) : Math.round(value * 0x7FFF);
            view.setInt16(index * 2, int16, true);
        }

        return bytesToBase64(bytes);
    }

    function blobToPcmPayload(blob, targetSampleRate) {
        const AudioContextCtor = root.AudioContext || root.webkitAudioContext;
        const desiredSampleRate = Math.max(8000, Number(targetSampleRate) || 16000);
        if (!AudioContextCtor || !blob || typeof blob.arrayBuffer !== 'function') {
            return Promise.reject(new Error('audio_decode_unavailable'));
        }

        const audioContext = new AudioContextCtor();
        return blob.arrayBuffer()
            .then(function (arrayBuffer) {
                return decodeAudioDataCompat(audioContext, arrayBuffer.slice(0));
            })
            .then(function (audioBuffer) {
                const monoSamples = mixAudioBufferToMono(audioBuffer);
                const pcmSamples = resampleFloat32Linear(monoSamples, audioBuffer.sampleRate || desiredSampleRate, desiredSampleRate);
                return {
                    pcm16Base64: float32ToPcm16Base64(pcmSamples),
                    sampleRate: desiredSampleRate,
                    channels: 1
                };
            })
            .finally(function () {
                if (audioContext && typeof audioContext.close === 'function') {
                    try {
                        audioContext.close();
                    } catch (_) {
                        // ignore
                    }
                }
            });
    }

    function normalizeSpeakingText(text, targetField) {
        let value = String(text || '').trim();
        if (!value) {
            return '';
        }

        if (String(targetField || '') === 'recording_ipa') {
            value = value
                .replace(/[\u02C8\u02CC'’]/gu, '')
                .replace(/\s+/gu, ' ')
                .trim();
            return value;
        }

        value = value
            .replace(/<[^>]*>/g, ' ')
            .replace(/[\r\n\t\u00A0]+/gu, ' ')
            .replace(/\s+/gu, ' ')
            .trim()
            .toLowerCase();

        return value;
    }

    function tokenizeSpeakingText(text, targetField) {
        const normalized = normalizeSpeakingText(text, targetField);
        if (!normalized) {
            return [];
        }

        if (String(targetField || '') === 'recording_ipa') {
            const parts = normalized.split(/\s+/u).filter(Boolean);
            return parts.length ? parts : [normalized];
        }

        const parts = normalized.split(/\s+/u).filter(Boolean);
        if (parts.length > 1) {
            return parts;
        }

        return Array.from(normalized);
    }

    function levenshteinArray(left, right) {
        const a = Array.isArray(left) ? left.slice() : [];
        const b = Array.isArray(right) ? right.slice() : [];
        if (!a.length) {
            return b.length;
        }
        if (!b.length) {
            return a.length;
        }

        let previous = new Array(b.length + 1);
        for (let index = 0; index <= b.length; index += 1) {
            previous[index] = index;
        }

        for (let i = 1; i <= a.length; i += 1) {
            const current = [i];
            for (let j = 1; j <= b.length; j += 1) {
                const substitutionCost = a[i - 1] === b[j - 1] ? 0 : 1;
                current[j] = Math.min(
                    previous[j] + 1,
                    current[j - 1] + 1,
                    previous[j - 1] + substitutionCost
                );
            }
            previous = current;
        }

        return previous[b.length] || 0;
    }

    function weightedLevenshteinArray(left, right, substitutionCost, insertDeleteCost) {
        const a = Array.isArray(left) ? left.slice() : [];
        const b = Array.isArray(right) ? right.slice() : [];
        const stepCost = Math.max(0, Number(insertDeleteCost) || 1);
        if (!a.length) {
            return b.length * stepCost;
        }
        if (!b.length) {
            return a.length * stepCost;
        }

        let previous = new Array(b.length + 1);
        for (let index = 0; index <= b.length; index += 1) {
            previous[index] = index * stepCost;
        }

        for (let i = 1; i <= a.length; i += 1) {
            const current = [i * stepCost];
            for (let j = 1; j <= b.length; j += 1) {
                const substitution = Math.max(0, Math.min(1, Number(substitutionCost(a[i - 1], b[j - 1])) || 0));
                current[j] = Math.min(
                    previous[j] + stepCost,
                    current[j - 1] + stepCost,
                    previous[j - 1] + substitution
                );
            }
            previous = current;
        }

        return Number(previous[b.length] || 0);
    }

    function getOfflineIpaFeatureMap() {
        if (getOfflineIpaFeatureMap.cache) {
            return getOfflineIpaFeatureMap.cache;
        }

        getOfflineIpaFeatureMap.cache = {
            i: { type: 'vowel', height: 0, back: 0, round: 0 },
            y: { type: 'vowel', height: 0, back: 0, round: 1 },
            'ɨ': { type: 'vowel', height: 0, back: 2, round: 0 },
            'ʉ': { type: 'vowel', height: 0, back: 2, round: 1 },
            'ɯ': { type: 'vowel', height: 0, back: 4, round: 0 },
            u: { type: 'vowel', height: 0, back: 4, round: 1 },
            'ɪ': { type: 'vowel', height: 1, back: 0.5, round: 0 },
            'ʏ': { type: 'vowel', height: 1, back: 0.5, round: 1 },
            'ʊ': { type: 'vowel', height: 1, back: 3.5, round: 1 },
            e: { type: 'vowel', height: 2, back: 0, round: 0 },
            'ø': { type: 'vowel', height: 2, back: 0, round: 1 },
            'ɘ': { type: 'vowel', height: 2, back: 2, round: 0 },
            'ɵ': { type: 'vowel', height: 2, back: 2, round: 1 },
            'ɤ': { type: 'vowel', height: 2, back: 4, round: 0 },
            o: { type: 'vowel', height: 2, back: 4, round: 1 },
            'ə': { type: 'vowel', height: 3, back: 2, round: 0 },
            'ɛ': { type: 'vowel', height: 4, back: 0, round: 0 },
            'œ': { type: 'vowel', height: 4, back: 0, round: 1 },
            'ɜ': { type: 'vowel', height: 4, back: 2, round: 0 },
            'ɞ': { type: 'vowel', height: 4, back: 2, round: 1 },
            'ʌ': { type: 'vowel', height: 4, back: 3.5, round: 0 },
            'ɔ': { type: 'vowel', height: 4, back: 4, round: 1 },
            'æ': { type: 'vowel', height: 5, back: 0, round: 0 },
            'ɐ': { type: 'vowel', height: 5, back: 2, round: 0 },
            a: { type: 'vowel', height: 6, back: 1.5, round: 0 },
            'ɶ': { type: 'vowel', height: 6, back: 0, round: 1 },
            'ɑ': { type: 'vowel', height: 6, back: 4, round: 0 },
            'ɒ': { type: 'vowel', height: 6, back: 4, round: 1 },
            j: { type: 'glide', height: 0, back: 0, round: 0 },
            'ɥ': { type: 'glide', height: 0, back: 0, round: 1 },
            'ɰ': { type: 'glide', height: 0, back: 4, round: 0 },
            w: { type: 'glide', height: 0, back: 4, round: 1 },
            p: { type: 'consonant', place: 0, manner: 'stop', voice: 0, lateral: 0, rhotic: 0 },
            b: { type: 'consonant', place: 0, manner: 'stop', voice: 1, lateral: 0, rhotic: 0 },
            m: { type: 'consonant', place: 0, manner: 'nasal', voice: 1, lateral: 0, rhotic: 0 },
            f: { type: 'consonant', place: 1, manner: 'fricative', voice: 0, lateral: 0, rhotic: 0 },
            v: { type: 'consonant', place: 1, manner: 'fricative', voice: 1, lateral: 0, rhotic: 0 },
            t: { type: 'consonant', place: 3, manner: 'stop', voice: 0, lateral: 0, rhotic: 0 },
            d: { type: 'consonant', place: 3, manner: 'stop', voice: 1, lateral: 0, rhotic: 0 },
            n: { type: 'consonant', place: 3, manner: 'nasal', voice: 1, lateral: 0, rhotic: 0 },
            s: { type: 'consonant', place: 3, manner: 'fricative', voice: 0, lateral: 0, rhotic: 0 },
            z: { type: 'consonant', place: 3, manner: 'fricative', voice: 1, lateral: 0, rhotic: 0 },
            'ɾ': { type: 'consonant', place: 3, manner: 'tap', voice: 1, lateral: 0, rhotic: 1 },
            r: { type: 'consonant', place: 3, manner: 'trill', voice: 1, lateral: 0, rhotic: 1 },
            'ɹ': { type: 'consonant', place: 3, manner: 'approximant', voice: 1, lateral: 0, rhotic: 1 },
            l: { type: 'consonant', place: 3, manner: 'approximant', voice: 1, lateral: 1, rhotic: 0 },
            'ʃ': { type: 'consonant', place: 4, manner: 'fricative', voice: 0, lateral: 0, rhotic: 0 },
            'ʒ': { type: 'consonant', place: 4, manner: 'fricative', voice: 1, lateral: 0, rhotic: 0 },
            c: { type: 'consonant', place: 6, manner: 'stop', voice: 0, lateral: 0, rhotic: 0 },
            'ɟ': { type: 'consonant', place: 6, manner: 'stop', voice: 1, lateral: 0, rhotic: 0 },
            'ɲ': { type: 'consonant', place: 6, manner: 'nasal', voice: 1, lateral: 0, rhotic: 0 },
            'ç': { type: 'consonant', place: 6, manner: 'fricative', voice: 0, lateral: 0, rhotic: 0 },
            k: { type: 'consonant', place: 7, manner: 'stop', voice: 0, lateral: 0, rhotic: 0 },
            g: { type: 'consonant', place: 7, manner: 'stop', voice: 1, lateral: 0, rhotic: 0 },
            'ŋ': { type: 'consonant', place: 7, manner: 'nasal', voice: 1, lateral: 0, rhotic: 0 },
            x: { type: 'consonant', place: 7, manner: 'fricative', voice: 0, lateral: 0, rhotic: 0 },
            'ɣ': { type: 'consonant', place: 7, manner: 'fricative', voice: 1, lateral: 0, rhotic: 0 },
            q: { type: 'consonant', place: 8, manner: 'stop', voice: 0, lateral: 0, rhotic: 0 },
            'ɢ': { type: 'consonant', place: 8, manner: 'stop', voice: 1, lateral: 0, rhotic: 0 },
            'χ': { type: 'consonant', place: 8, manner: 'fricative', voice: 0, lateral: 0, rhotic: 0 },
            'ʁ': { type: 'consonant', place: 8, manner: 'fricative', voice: 1, lateral: 0, rhotic: 1 },
            h: { type: 'consonant', place: 10, manner: 'fricative', voice: 0, lateral: 0, rhotic: 0 },
            'ʔ': { type: 'consonant', place: 10, manner: 'stop', voice: 0, lateral: 0, rhotic: 0 }
        };

        return getOfflineIpaFeatureMap.cache;
    }

    function parseIpaSimilarityToken(token) {
        const normalized = normalizeSpeakingText(token, 'recording_ipa');
        if (!normalized) {
            return { baseUnits: [], modifiers: [] };
        }

        const chars = Array.from(normalized);
        const baseUnits = [];
        const modifiers = [];
        chars.forEach(function (char) {
            if (/\s/u.test(char) || char === '.' || char === '·' || char === '‿' || char === '|' || char === '‖') {
                return;
            }
            if (char === '͡' || char === '͜') {
                return;
            }
            if (/[\u0300-\u036F]/u.test(char) || /[ʰʷʲˠˤʱːˑ]/u.test(char)) {
                modifiers.push(char);
                return;
            }
            baseUnits.push(char);
        });

        return {
            baseUnits: baseUnits.length ? baseUnits : [normalized],
            modifiers: Array.from(new Set(modifiers))
        };
    }

    function ipaModifierWeight(modifier) {
        const weights = {
            'ʰ': 0.08,
            'ʷ': 0.08,
            'ʲ': 0.08,
            'ˠ': 0.08,
            'ˤ': 0.08,
            'ʱ': 0.08,
            '̪': 0.06,
            '̥': 0.06,
            '̬': 0.06,
            '̟': 0.05,
            '̠': 0.05,
            '̃': 0.06,
            'ː': 0.05,
            'ˑ': 0.04
        };
        return Number(weights[String(modifier || '')]) || 0.06;
    }

    function ipaModifierPenalty(leftModifiers, rightModifiers) {
        const left = Array.from(new Set(Array.isArray(leftModifiers) ? leftModifiers.map(String).filter(Boolean) : []));
        const right = Array.from(new Set(Array.isArray(rightModifiers) ? rightModifiers.map(String).filter(Boolean) : []));
        const difference = left.filter(function (modifier) {
            return right.indexOf(modifier) === -1;
        }).concat(right.filter(function (modifier) {
            return left.indexOf(modifier) === -1;
        }));

        if (!difference.length) {
            return 0;
        }

        return Math.min(0.32, difference.reduce(function (total, modifier) {
            return total + ipaModifierWeight(modifier);
        }, 0));
    }

    function ipaSymbolSimilarity(leftSymbol, rightSymbol) {
        const left = String(leftSymbol || '').trim();
        const right = String(rightSymbol || '').trim();
        if (!left || !right) {
            return 0;
        }
        if (left === right) {
            return 1;
        }

        const featureMap = getOfflineIpaFeatureMap();
        const leftFeatures = featureMap[left];
        const rightFeatures = featureMap[right];
        if (!leftFeatures || !rightFeatures) {
            return 0;
        }

        const leftType = String(leftFeatures.type || '');
        const rightType = String(rightFeatures.type || '');
        const leftVowelLike = leftType === 'vowel' || leftType === 'glide';
        const rightVowelLike = rightType === 'vowel' || rightType === 'glide';

        if (leftVowelLike && rightVowelLike) {
            const heightPenalty = Math.abs(Number(leftFeatures.height || 0) - Number(rightFeatures.height || 0)) / 6;
            const backPenalty = Math.abs(Number(leftFeatures.back || 0) - Number(rightFeatures.back || 0)) / 4;
            const roundPenalty = Math.abs(Number(leftFeatures.round || 0) - Number(rightFeatures.round || 0));
            const typePenalty = leftType === rightType ? 0 : 0.12;
            return Math.max(0, Math.min(1, 1 - ((heightPenalty * 0.45) + (backPenalty * 0.35) + (roundPenalty * 0.2) + typePenalty)));
        }

        if (leftType === 'consonant' && rightType === 'consonant') {
            const leftManner = String(leftFeatures.manner || '');
            const rightManner = String(rightFeatures.manner || '');
            let mannerPenalty = 0.34;
            if (leftManner === rightManner) {
                mannerPenalty = 0;
            } else if (['tap', 'trill', 'approximant'].indexOf(leftManner) !== -1 && ['tap', 'trill', 'approximant'].indexOf(rightManner) !== -1) {
                mannerPenalty = 0.14;
            } else if (['fricative', 'approximant'].indexOf(leftManner) !== -1 && ['fricative', 'approximant'].indexOf(rightManner) !== -1) {
                mannerPenalty = 0.2;
            } else if (['stop', 'fricative'].indexOf(leftManner) !== -1 && ['stop', 'fricative'].indexOf(rightManner) !== -1) {
                mannerPenalty = 0.28;
            } else if (['stop', 'nasal'].indexOf(leftManner) !== -1 && ['stop', 'nasal'].indexOf(rightManner) !== -1) {
                mannerPenalty = 0.24;
            }

            const placePenalty = Math.min(0.36, Math.abs(Number(leftFeatures.place || 0) - Number(rightFeatures.place || 0)) * 0.075);
            const voicePenalty = Number(leftFeatures.voice || 0) === Number(rightFeatures.voice || 0) ? 0 : 0.1;
            const lateralPenalty = Number(leftFeatures.lateral || 0) === Number(rightFeatures.lateral || 0) ? 0 : 0.08;
            const rhoticPenalty = Number(leftFeatures.rhotic || 0) === Number(rightFeatures.rhotic || 0) ? 0 : 0.06;
            return Math.max(0, Math.min(1, 1 - (mannerPenalty + placePenalty + voicePenalty + lateralPenalty + rhoticPenalty)));
        }

        return 0;
    }

    function ipaTokenSimilarity(leftToken, rightToken) {
        const left = String(leftToken || '').trim();
        const right = String(rightToken || '').trim();
        if (!left || !right) {
            return 0;
        }
        if (left === right) {
            return 1;
        }

        const leftParts = parseIpaSimilarityToken(left);
        const rightParts = parseIpaSimilarityToken(right);
        const leftUnits = leftParts.baseUnits || [];
        const rightUnits = rightParts.baseUnits || [];
        if (!leftUnits.length || !rightUnits.length) {
            return 0;
        }

        const baseDistance = weightedLevenshteinArray(leftUnits, rightUnits, function (leftUnit, rightUnit) {
            return 1 - ipaSymbolSimilarity(leftUnit, rightUnit);
        }, 1);
        const baseScore = Math.max(0, 1 - (baseDistance / Math.max(leftUnits.length, rightUnits.length, 1)));
        const modifierPenalty = ipaModifierPenalty(leftParts.modifiers || [], rightParts.modifiers || []);

        return Math.max(0, Math.min(1, baseScore - modifierPenalty));
    }

    function speakingSimilarityScore(expected, actual, targetField) {
        const field = String(targetField || '');
        const expectedTokens = tokenizeSpeakingText(expected, field);
        const actualTokens = tokenizeSpeakingText(actual, field);
        if (!expectedTokens.length || !actualTokens.length) {
            return 0;
        }
        if (expectedTokens.join('\u0001') === actualTokens.join('\u0001')) {
            return 100;
        }

        if (field === 'recording_ipa') {
            const tokenDistance = weightedLevenshteinArray(expectedTokens, actualTokens, function (leftToken, rightToken) {
                return 1 - ipaTokenSimilarity(leftToken, rightToken);
            }, 1);
            const maxTokens = Math.max(expectedTokens.length, actualTokens.length, 1);
            const tokenScore = Math.max(0, (1 - (tokenDistance / maxTokens)) * 100);
            const expectedUnits = expectedTokens.reduce(function (units, token) {
                return units.concat(parseIpaSimilarityToken(token).baseUnits || []);
            }, []);
            const actualUnits = actualTokens.reduce(function (units, token) {
                return units.concat(parseIpaSimilarityToken(token).baseUnits || []);
            }, []);
            if (!expectedUnits.length || !actualUnits.length) {
                return Math.round(tokenScore * 100) / 100;
            }
            const unitDistance = weightedLevenshteinArray(expectedUnits, actualUnits, function (leftUnit, rightUnit) {
                return 1 - ipaSymbolSimilarity(leftUnit, rightUnit);
            }, 1);
            const maxUnits = Math.max(expectedUnits.length, actualUnits.length, 1);
            const unitScore = Math.max(0, (1 - (unitDistance / maxUnits)) * 100);
            return Math.round((((tokenScore * 0.72) + (unitScore * 0.28)) * 100)) / 100;
        }

        const tokenDistance = levenshteinArray(expectedTokens, actualTokens);
        const maxTokens = Math.max(expectedTokens.length, actualTokens.length, 1);
        const tokenScore = Math.max(0, (1 - (tokenDistance / maxTokens)) * 100);
        const expectedString = expectedTokens.join(' ');
        const actualString = actualTokens.join(' ');
        const charDistance = levenshteinArray(Array.from(expectedString), Array.from(actualString));
        const maxChars = Math.max(expectedString.length, actualString.length, 1);
        const charScore = Math.max(0, (1 - (charDistance / maxChars)) * 100);
        return Math.round((((tokenScore + charScore) / 2) * 100)) / 100;
    }

    function speakingScoreBucket(score) {
        const numericScore = Number(score) || 0;
        if (numericScore >= 90) {
            return 'right';
        }
        if (numericScore >= 65) {
            return 'close';
        }
        return 'wrong';
    }

    function getEmbeddedSttRuntime() {
        if (root.LLToolsOfflineEmbeddedStt && typeof root.LLToolsOfflineEmbeddedStt === 'object') {
            return root.LLToolsOfflineEmbeddedStt;
        }
        if (root.Capacitor && root.Capacitor.Plugins && root.Capacitor.Plugins.LLToolsOfflineStt) {
            return root.Capacitor.Plugins.LLToolsOfflineStt;
        }
        if (root.LLToolsOfflineAndroid && typeof root.LLToolsOfflineAndroid === 'object') {
            return root.LLToolsOfflineAndroid;
        }
        return null;
    }

    function buildOfflineSpeakingBridge() {
        function resolveEntryModel(entry) {
            if (entry && entry.embedded_model && typeof entry.embedded_model === 'object') {
                return entry.embedded_model;
            }
            if (entry && entry.offline_stt && typeof entry.offline_stt === 'object') {
                return entry.offline_stt;
            }
            return null;
        }

        return {
            checkSpeakingAvailability: function (entry) {
                const model = resolveEntryModel(entry);
                if (!model || !String(model.webPath || '').trim()) {
                    return Promise.resolve(false);
                }

                const runtime = getEmbeddedSttRuntime();
                if (!runtime) {
                    return Promise.resolve(false);
                }

                if (typeof runtime.isEmbeddedSttAvailable === 'function') {
                    try {
                        const result = runtime.isEmbeddedSttAvailable(model);
                        return Promise.resolve(result).then(function (payload) {
                            if (payload && typeof payload === 'object' && Object.prototype.hasOwnProperty.call(payload, 'available')) {
                                return !!payload.available;
                            }
                            return !!payload;
                        }).catch(function () {
                            return false;
                        });
                    } catch (_) {
                        return Promise.resolve(false);
                    }
                }

                return Promise.resolve(
                    typeof runtime.transcribePcm === 'function'
                    || typeof runtime.transcribe === 'function'
                    || typeof runtime.transcribeSpeakingAttempt === 'function'
                    || typeof runtime.transcribeBase64 === 'function'
                );
            },
            transcribeSpeakingAttempt: function (blob, run) {
                const runtime = getEmbeddedSttRuntime();
                const model = run && run.embeddedModel && typeof run.embeddedModel === 'object'
                    ? run.embeddedModel
                    : {};
                if (!runtime) {
                    return Promise.reject(new Error('embedded_stt_unavailable'));
                }

                if (typeof runtime.transcribePcm === 'function') {
                    return blobToPcmPayload(blob, 16000).then(function (pcmPayload) {
                        return runtime.transcribePcm({
                            pcm16Base64: pcmPayload.pcm16Base64,
                            sampleRate: pcmPayload.sampleRate,
                            channels: pcmPayload.channels,
                            model: model
                        });
                    });
                }

                if (typeof runtime.transcribe === 'function') {
                    return Promise.resolve(runtime.transcribe({
                        audioBlob: blob,
                        mimeType: blob && blob.type ? String(blob.type) : '',
                        model: model
                    }));
                }

                return blobToBase64(blob).then(function (audioBase64) {
                    if (typeof runtime.transcribeSpeakingAttempt === 'function') {
                        return runtime.transcribeSpeakingAttempt(audioBase64, String(blob.type || ''), String(model.webPath || ''));
                    }
                    if (typeof runtime.transcribeBase64 === 'function') {
                        return runtime.transcribeBase64({
                            audioBase64: audioBase64,
                            mimeType: String(blob.type || ''),
                            model: model
                        });
                    }
                    throw new Error('embedded_stt_unavailable');
                });
            },
            scoreSpeakingAttempt: function (run, transcript) {
                const target = (run && run.prompt && run.prompt.target && typeof run.prompt.target === 'object')
                    ? run.prompt.target
                    : {};
                const targetField = String(run && run.targetField || target.speaking_target_field || '');
                const displayTexts = (target.speaking_display_texts && typeof target.speaking_display_texts === 'object')
                    ? {
                        title: String(target.speaking_display_texts.title || ''),
                        ipa: String(target.speaking_display_texts.ipa || ''),
                        target_text: String(target.speaking_display_texts.target_text || ''),
                        target_field: String(target.speaking_display_texts.target_field || targetField),
                        target_label: String(target.speaking_display_texts.target_label || target.speaking_target_label || '')
                    }
                    : {
                        title: String(target.title || ''),
                        ipa: '',
                        target_text: String(target.speaking_target_text || ''),
                        target_field: targetField,
                        target_label: String(target.speaking_target_label || '')
                    };
                const targetText = String(target.speaking_target_text || displayTexts.target_text || '');
                const normalizedTarget = normalizeSpeakingText(targetText, targetField);
                const normalizedTranscript = normalizeSpeakingText(transcript, targetField);
                const score = speakingSimilarityScore(normalizedTarget, normalizedTranscript, targetField);
                return Promise.resolve({
                    word_id: toInt(target.id),
                    target_field: targetField,
                    target_label: String(target.speaking_target_label || displayTexts.target_label || ''),
                    target_text: targetText,
                    normalized_target_text: normalizedTarget,
                    normalized_transcript_text: normalizedTranscript,
                    score: score,
                    bucket: speakingScoreBucket(score),
                    display_texts: displayTexts,
                    best_correct_audio_url: String(target.speaking_best_correct_audio_url || '')
                });
            }
        };
    }

    function initOfflineGames() {
        if (!documentRef || !gamesPayload || !Object.keys(gamesPayload).length) {
            return;
        }

        const gamesView = documentRef.getElementById('ll-offline-games-view');
        if (!gamesView) {
            return;
        }

        const viewToggleButtons = getOfflineGamesViewToggleButtons();
        const bridge = buildOfflineSpeakingBridge();
        let gamesInitialized = false;

        function ensureGamesInitialized() {
            if (gamesInitialized || !root.LLWordsetGames || typeof root.LLWordsetGames.init !== 'function') {
                return;
            }

            const flashData = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData === 'object')
                ? root.llToolsFlashcardsData
                : {};
            const categories = Array.isArray(flashData.categories) ? flashData.categories : [];
            root.LLWordsetGames.init(gamesView, {
                runtimeMode: 'offline',
                ajaxUrl: '',
                nonce: '',
                isLoggedIn: true,
                wordsetId: Array.isArray(flashData.wordsetIds) && flashData.wordsetIds.length ? toInt(flashData.wordsetIds[0]) : 0,
                visibleCategoryIds: categories.map(function (category) {
                    return toInt(category && category.id);
                }).filter(Boolean),
                i18n: Object.assign({}, messages, (gamesPayload.i18n && typeof gamesPayload.i18n === 'object') ? gamesPayload.i18n : {}),
                games: gamesPayload,
                offlineBridge: bridge
            });
            gamesInitialized = true;
        }

        viewToggleButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                const targetView = String(button.getAttribute('data-target-view') || 'study');
                setOfflineActiveView(targetView);
                if (targetView === 'games') {
                    ensureGamesInitialized();
                }
            });
        });

        gamesView.addEventListener('click', function (event) {
            const target = event.target;
            if (!target || typeof target.closest !== 'function') {
                return;
            }

            const backLink = target.closest('[data-ll-wordset-games-back]');
            if (backLink) {
                event.preventDefault();
                setOfflineActiveView('study');
            }
        });
    }

    function initOfflineSyncPanel() {
        if (!documentRef) {
            return;
        }

        const tracker = getProgressTracker();
        const panel = documentRef.getElementById('ll-offline-sync-panel');
        const statusEl = documentRef.getElementById('ll-offline-sync-status');
        const metaEl = documentRef.getElementById('ll-offline-sync-meta');
        const feedbackEl = documentRef.getElementById('ll-offline-sync-feedback');
        const connectButton = documentRef.getElementById('ll-offline-sync-connect');
        const syncNowButton = documentRef.getElementById('ll-offline-sync-now');
        const disconnectButton = documentRef.getElementById('ll-offline-sync-disconnect');
        const sheet = documentRef.getElementById('ll-offline-sync-sheet');
        const form = documentRef.getElementById('ll-offline-sync-form');
        const identifierInput = documentRef.getElementById('ll-offline-sync-identifier');
        const passwordInput = documentRef.getElementById('ll-offline-sync-password');
        const passwordToggle = documentRef.getElementById('ll-offline-sync-password-toggle');
        const cancelButton = documentRef.getElementById('ll-offline-sync-cancel');
        const submitButton = documentRef.getElementById('ll-offline-sync-submit');
        const sheetFeedbackEl = documentRef.getElementById('ll-offline-sync-sheet-feedback');
        const sheetTitleEl = documentRef.getElementById('ll-offline-sync-sheet-title');

        if (!panel || !statusEl || !metaEl || !connectButton || !syncNowButton || !disconnectButton || !sheet
            || !form || !identifierInput || !passwordInput || !passwordToggle || !cancelButton || !submitButton
            || !tracker || typeof tracker.syncFromServer !== 'function' || typeof tracker.setOfflineSyncSession !== 'function'
            || typeof tracker.clearOfflineSyncSession !== 'function' || !syncConfig.enabled
            || !String(syncConfig.ajaxUrl || '').trim()
        ) {
            return;
        }

        connectButton.textContent = syncMessages.connectButton;
        syncNowButton.textContent = syncMessages.syncNowButton;
        disconnectButton.textContent = syncMessages.disconnectButton;
        cancelButton.textContent = syncMessages.syncCancelButton;
        submitButton.textContent = syncMessages.syncSubmitButton;
        if (sheetTitleEl) {
            sheetTitleEl.textContent = syncMessages.syncFormTitle;
        }
        passwordToggle.setAttribute('aria-label', syncMessages.showPasswordLabel);
        panel.hidden = false;

        function setFeedback(element, message, isError) {
            if (!element) {
                return;
            }
            const text = String(message || '');
            element.hidden = !text;
            element.textContent = text;
            element.style.color = isError ? '#8e2b18' : '#1f5f4a';
        }

        function closeSheet() {
            sheet.hidden = true;
            setFeedback(sheetFeedbackEl, '', false);
            if (passwordInput) {
                passwordInput.type = 'password';
            }
            passwordToggle.setAttribute('aria-pressed', 'false');
        }

        function openSheet() {
            setFeedback(sheetFeedbackEl, '', false);
            sheet.hidden = false;
            if (identifierInput && !identifierInput.value) {
                identifierInput.focus();
            }
        }

        function updatePanel(info) {
            const state = (info && typeof info === 'object') ? info : tracker.getSyncState();
            const auth = (state.auth && typeof state.auth === 'object') ? state.auth : {};
            const user = (auth.user && typeof auth.user === 'object') ? auth.user : null;
            const connected = !!(state.connected && user && user.id);
            const pending = Math.max(0, parseInt(state.pending, 10) || 0);
            statusEl.textContent = connected
                ? formatMessage(syncMessages.connectedAsLabel, [user.display_name || user.login || ''])
                : syncMessages.localOnlyLabel;

            if (pending > 0) {
                metaEl.textContent = formatMessage(syncMessages.syncPendingLabel, [pending]);
            } else if (auth.last_sync_at) {
                metaEl.textContent = syncMessages.syncIdleLabel;
            } else {
                metaEl.textContent = '';
            }

            connectButton.hidden = connected;
            syncNowButton.hidden = !connected;
            disconnectButton.hidden = !connected;

            if (auth.last_error) {
                setFeedback(feedbackEl, auth.last_error || syncMessages.syncFailedLabel, true);
            } else {
                setFeedback(feedbackEl, '', false);
            }
        }

        function postSyncAction(action, payload) {
            const formData = new root.FormData();
            formData.append('action', action);
            Object.keys(payload || {}).forEach(function (key) {
                formData.append(key, String(payload[key] || ''));
            });
            return fetch(String(syncConfig.ajaxUrl || ''), {
                method: 'POST',
                body: formData,
                credentials: 'omit'
            }).then(function (response) {
                return response.text().then(function (rawText) {
                    let parsed = null;
                    try {
                        parsed = rawText ? JSON.parse(rawText) : null;
                    } catch (_) {
                        parsed = null;
                    }
                    if (response.ok && parsed && parsed.success) {
                        return parsed.data || {};
                    }
                    throw new Error((parsed && parsed.data && parsed.data.message) ? parsed.data.message : launcherMessages.somethingWentWrong);
                });
            });
        }

        function syncNow(options) {
            const opts = (options && typeof options === 'object') ? options : {};
            const silent = !!opts.silent;
            if (!silent) {
                setFeedback(feedbackEl, syncMessages.syncInProgressLabel, false);
            }
            return tracker.syncFromServer({
                wordIds: collectOfflineWordIds()
            }).then(function (result) {
                const payload = result && result.data && typeof result.data === 'object' ? result.data : null;
                if (payload && payload.state) {
                    applyOfflineStudyState(payload.state);
                }
                updatePanel();
                if (!silent) {
                    if (result && result.failed) {
                        setFeedback(feedbackEl, result.error || syncMessages.syncFailedLabel, true);
                    } else if (Math.max(0, parseInt((tracker.getSyncState().pending || 0), 10) || 0) > 0) {
                        setFeedback(feedbackEl, formatMessage(syncMessages.syncPendingLabel, [tracker.getSyncState().pending]), false);
                    } else {
                        setFeedback(feedbackEl, syncMessages.syncIdleLabel, false);
                    }
                }
                return result;
            }).catch(function (error) {
                updatePanel();
                if (!silent) {
                    setFeedback(feedbackEl, error && error.message ? error.message : syncMessages.syncFailedLabel, true);
                }
                return { failed: true, error: error && error.message ? error.message : syncMessages.syncFailedLabel };
            });
        }

        passwordToggle.addEventListener('click', function () {
            const nextVisible = passwordToggle.getAttribute('aria-pressed') !== 'true';
            passwordToggle.setAttribute('aria-pressed', nextVisible ? 'true' : 'false');
            passwordInput.type = nextVisible ? 'text' : 'password';
            passwordToggle.setAttribute('aria-label', nextVisible ? syncMessages.hidePasswordLabel : syncMessages.showPasswordLabel);
        });

        connectButton.addEventListener('click', openSheet);
        cancelButton.addEventListener('click', closeSheet);
        sheet.addEventListener('click', function (event) {
            if (event.target === sheet) {
                closeSheet();
            }
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            setFeedback(sheetFeedbackEl, '', false);
            const identifier = String(identifierInput.value || '').trim();
            const password = String(passwordInput.value || '');
            if (!identifier || !password) {
                setFeedback(sheetFeedbackEl, launcherMessages.somethingWentWrong, true);
                return;
            }

            submitButton.disabled = true;
            postSyncAction(syncConfig.loginAction || 'll_tools_offline_app_login', {
                identifier: identifier,
                password: password,
                device_id: tracker.getSyncState().device_id || '',
                profile_id: tracker.getSyncState().profile_id || ''
            }).then(function (payload) {
                tracker.setOfflineSyncSession({
                    auth_token: payload.auth_token || '',
                    expires_at: payload.expires_at || '',
                    user: payload.user || null
                });
                closeSheet();
                updatePanel();
                return syncNow({ silent: false });
            }).catch(function (error) {
                setFeedback(sheetFeedbackEl, error && error.message ? error.message : launcherMessages.somethingWentWrong, true);
            }).finally(function () {
                submitButton.disabled = false;
            });
        });

        syncNowButton.addEventListener('click', function () {
            syncNow({ silent: false });
        });

        disconnectButton.addEventListener('click', function () {
            const state = tracker.getSyncState();
            const auth = state && state.auth ? state.auth : {};
            const token = String(auth.token || '');
            const clearLocal = function () {
                tracker.clearOfflineSyncSession();
                updatePanel();
                setFeedback(feedbackEl, syncMessages.syncSignedOutLabel, false);
            };

            if (!token) {
                clearLocal();
                return;
            }

            postSyncAction(syncConfig.logoutAction || 'll_tools_offline_app_logout', {
                auth_token: token
            }).catch(function () {
                return null;
            }).finally(clearLocal);
        });

        if ($ && typeof $.fn !== 'undefined') {
            $(document).on('lltools:offline-sync-state-changed', function (_event, info) {
                updatePanel(info);
            });
            $(document).on('lltools:remote-sync-snapshot', function (_event, payload) {
                if (payload && payload.state && typeof payload.state === 'object') {
                    applyOfflineStudyState(payload.state);
                }
                updatePanel();
            });
        }

        updatePanel();

        const initialState = tracker.getSyncState();
        if (initialState.connected) {
            syncNow({ silent: true });
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
        const selectionBarEl = documentRef.getElementById('ll-offline-selection-bar');
        const selectionTextEl = documentRef.getElementById('ll-offline-selection-text');
        const selectAllButton = documentRef.getElementById('ll-offline-select-all');
        const selectAllWrap = selectAllButton ? selectAllButton.closest('.ll-wordset-grid-tools') : null;
        const selectionButtons = {
            learning: documentRef.getElementById('ll-offline-launch-learning-selected'),
            practice: documentRef.getElementById('ll-offline-launch-practice-selected'),
            listening: documentRef.getElementById('ll-offline-launch-listening-selected'),
            gender: documentRef.getElementById('ll-offline-launch-gender-selected'),
            'self-check': documentRef.getElementById('ll-offline-launch-self-check-selected')
        };
        const clearSelectionButton = documentRef.getElementById('ll-offline-selection-clear');

        if (!rootEl || !launcherEl || !gridEl || !emptyEl || !selectionBarEl || !selectAllButton || !clearSelectionButton) {
            return;
        }

        const flashData = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData === 'object')
            ? root.llToolsFlashcardsData
            : {};
        const categories = getLauncherCategories(flashData);
        const offlineCategoryData = (flashData.offlineCategoryData && typeof flashData.offlineCategoryData === 'object')
            ? flashData.offlineCategoryData
            : {};
        let selectedIds = Array.isArray((flashData.userStudyState && flashData.userStudyState.category_ids) || [])
            ? flashData.userStudyState.category_ids.map(toInt).filter(Boolean)
            : [];

        function setModeButtonDisabled(buttonEl, disabled) {
            if (!buttonEl) {
                return;
            }

            buttonEl.disabled = !!disabled;
            buttonEl.setAttribute('aria-disabled', disabled ? 'true' : 'false');
            buttonEl.classList.toggle('is-disabled', !!disabled);
        }

        function syncSelectionButtons(selectedCategories) {
            const modes = getSelectionModes(categories, flashData);
            Object.keys(selectionButtons).forEach(function (mode) {
                const buttonEl = selectionButtons[mode];
                if (!buttonEl) {
                    return;
                }
                const visible = modes.indexOf(mode) !== -1;
                buttonEl.hidden = !visible;
                if (!visible) {
                    return;
                }
                buttonEl.innerHTML = buildSelectionActionMarkup(mode);
                setModeButtonDisabled(buttonEl, !isModeSupportedForSelection(mode, selectedCategories, flashData));
            });
        }

        function setSelectedIds(nextIds) {
            const nextLookup = {};
            (Array.isArray(nextIds) ? nextIds : []).forEach(function (categoryId) {
                const normalizedId = parseInt(categoryId, 10) || 0;
                if (normalizedId > 0) {
                    nextLookup[normalizedId] = true;
                }
            });

            selectedIds = categories.map(function (category) {
                return category.id;
            }).filter(function (categoryId) {
                return !!nextLookup[categoryId];
            });

            Array.prototype.forEach.call(launcherEl.querySelectorAll('[data-ll-offline-category-select]'), function (input) {
                const categoryId = parseInt(input.getAttribute('data-cat-id') || '', 10) || 0;
                input.checked = !!nextLookup[categoryId];
            });
            persistSelectedCategoryIds(selectedIds.slice());
        }

        function getSelectedCategories() {
            const selectedLookup = {};
            selectedIds.forEach(function (categoryId) {
                selectedLookup[categoryId] = true;
            });

            return categories.filter(function (category) {
                return !!selectedLookup[category.id];
            });
        }

        function setAllSelections(checked) {
            setSelectedIds(checked
                ? categories.map(function (category) { return category.id; })
                : []);
        }

        function syncSelectionUi() {
            const selectedCategories = getSelectedCategories();
            const selectedCount = selectedCategories.length;
            const selectedWordCount = selectedCategories.reduce(function (total, category) {
                return total + Math.max(0, parseInt(category.word_count, 10) || 0);
            }, 0);
            const selectionActive = selectedCount > 0;
            const allSelected = categories.length > 0 && selectedCount === categories.length;

            launcherEl.classList.toggle('ll-wordset-selection-active', selectionActive);
            rootEl.classList.toggle('ll-wordset-selection-active', selectionActive);
            selectionBarEl.hidden = !selectionActive;

            if (selectionTextEl) {
                selectionTextEl.textContent = selectionActive
                    ? formatMessage(launcherMessages.selectionWords, [selectedWordCount])
                    : launcherMessages.selectionLabel;
            }

            selectAllButton.textContent = allSelected ? launcherMessages.deselectAll : launcherMessages.selectAll;
            if (selectAllWrap) {
                selectAllWrap.hidden = categories.length < 2;
            }
            selectAllButton.hidden = categories.length < 2;
            selectAllButton.disabled = categories.length < 1;
            selectAllButton.setAttribute('aria-pressed', allSelected ? 'true' : 'false');
            syncSelectionButtons(selectedCategories);
        }

        function renderCategoryCards() {
            if (!categories.length) {
                emptyEl.hidden = false;
                emptyEl.textContent = launcherMessages.noCategories;
                gridEl.innerHTML = '';
                if (selectAllWrap) {
                    selectAllWrap.hidden = true;
                }
                selectAllButton.hidden = true;
                syncSelectionUi();
                return;
            }

            emptyEl.hidden = true;
            gridEl.innerHTML = categories.map(function (category) {
                const selectLabel = formatMessage(launcherMessages.selectCategory, [category.name]);
                const previewStyle = category.preview_aspect_ratio
                    ? ' style="--ll-wordset-preview-aspect: ' + escapeHtml(category.preview_aspect_ratio) + ';"'
                    : '';

                return '' +
                    '<article class="ll-wordset-card" role="listitem" data-category-id="' + category.id + '" data-cat-id="' + category.id + '" data-word-count="' + category.word_count + '">' +
                        '<div class="ll-wordset-card__top">' +
                            '<label class="ll-wordset-card__select" aria-label="' + escapeHtml(selectLabel) + '">' +
                                '<input type="checkbox" value="' + category.id + '" data-ll-offline-category-select data-cat-id="' + category.id + '">' +
                                '<span class="ll-wordset-card__select-box" aria-hidden="true"></span>' +
                            '</label>' +
                            '<div class="ll-wordset-card__heading">' +
                                '<h2 class="ll-wordset-card__title">' + escapeHtml(category.name) + '</h2>' +
                            '</div>' +
                            '<span class="ll-wordset-card__hide-spacer" aria-hidden="true"></span>' +
                        '</div>' +
                        '<div class="ll-wordset-card__lesson-link">' +
                            '<div class="ll-wordset-card__preview ' + (category.has_images ? 'has-images' : 'has-text') + '"' + previewStyle + '>' +
                                renderPreviewMarkup(category) +
                            '</div>' +
                        '</div>' +
                        '<div class="ll-wordset-card__quiz-actions">' +
                            getCardModesForCategory(category, flashData).map(function (mode) {
                                return buildCategoryActionMarkup(mode, category, flashData);
                            }).join('') +
                        '</div>' +
                    '</article>';
            }).join('');

            syncSelectionUi();
        }

        launcherEl.addEventListener('change', function (event) {
            const target = event.target;
            if (!target || typeof target.matches !== 'function' || !target.matches('[data-ll-offline-category-select]')) {
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

            setSelectedIds(selectedIds);

            syncSelectionUi();
        });

        launcherEl.addEventListener('click', function (event) {
            const target = event.target;
            const isElement = target && typeof target.closest === 'function';
            if (!isElement) {
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

            if (target.closest('#ll-offline-selection-clear')) {
                setAllSelections(false);
                syncSelectionUi();
                return;
            }

            if (target.closest('#ll-offline-select-all')) {
                const shouldSelectAll = selectedIds.length !== categories.length;
                setAllSelections(shouldSelectAll);
                syncSelectionUi();
            }
        });

        documentRef.addEventListener('lltools:offline-app-state-updated', function (event) {
            const detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
            const state = detail.state && typeof detail.state === 'object' ? detail.state : {};
            setSelectedIds(Array.isArray(state.category_ids) ? state.category_ids : []);
            syncSelectionUi();
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

        initOfflineSyncPanel();
        initOfflineLauncher();
        initOfflineGames();
        setOfflineActiveView('study');
    }

    root.LLToolsOfflineSync = buildOfflineSyncApi();
    if (root.llToolsFlashcardsData && root.llToolsFlashcardsData.isUserLoggedIn) {
        try {
            root.LLToolsOfflineSync.configureAuth({
                ajaxUrl: root.llToolsFlashcardsData.ajaxurl || '',
                nonce: root.llToolsFlashcardsData.userStudyNonce || '',
                isUserLoggedIn: !!root.llToolsFlashcardsData.isUserLoggedIn
            });
        } catch (_) { /* no-op */ }
    }
})(window);
