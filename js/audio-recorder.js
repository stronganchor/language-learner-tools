(function () {
    'use strict';

    let mediaRecorder = null;
    let audioChunks = [];
    let currentImageIndex = 0;
    let exhaustedCategories = new Set();
    let recordingStartTime = 0;
    let timerInterval = null;
    let currentBlob = null;
    let newWordMode = false;
    let newWordStage = 'inactive';
    let newWordUsingPanel = false;
    let newWordPrepared = false;
    let newWordTranscriptionDone = false;
    let newWordTranscriptionInFlight = false;
    let newWordTranslationInFlight = false;
    let newWordAutoCancelled = false;
    let newWordTranscribeAbort = null;
    let newWordTranslateAbort = null;
    let newWordAutoHideTimer = null;
    let suppressNextScroll = false;
    let newWordCategoryTypeCache = {};
    let newWordCategoryTypeAbort = null;
    let newWordLastSaved = { target: '', translation: '' };
    let lastTranslationSource = '';
    let activeRecordingControls = null;
    let savedExistingState = null;
    let lastNewWordCategory = null;
    let processingState = null;
    let processingAudioContext = null;
    let hiddenWordsPanelOpen = false;

    const images = window.ll_recorder_data?.images || [];
    const ajaxUrl = window.ll_recorder_data?.ajax_url;
    const nonce = window.ll_recorder_data?.nonce;
    const requireAll = !!window.ll_recorder_data?.require_all_types;
    const allowNewWords = !!window.ll_recorder_data?.allow_new_words;
    const i18n = window.ll_recorder_data?.i18n || {};
    const sortLocales = buildSortLocales(window.ll_recorder_data?.sort_locale || document.documentElement.lang || '');
    const turkishSortLocales = withTurkishSortLocales(sortLocales);
    const autoProcessEnabled = !!window.ll_recorder_data?.auto_process_recordings;
    const hasAssemblyAI = !!window.ll_recorder_data?.assembly_enabled;
    const hasDeepl = !!window.ll_recorder_data?.deepl_enabled;
    const recordingTypeOrder = Array.isArray(window.ll_recorder_data?.recording_type_order)
        ? window.ll_recorder_data.recording_type_order
        : ['isolation', 'introduction', 'question', 'sentence'];
    const recordingTypeIcons = (window.ll_recorder_data && typeof window.ll_recorder_data.recording_type_icons === 'object' && window.ll_recorder_data.recording_type_icons !== null)
        ? window.ll_recorder_data.recording_type_icons
        : {};
    const transcribePollAttemptsRaw = parseInt(window.ll_recorder_data?.transcribe_poll_attempts, 10);
    const transcribePollIntervalRaw = parseInt(window.ll_recorder_data?.transcribe_poll_interval_ms, 10);
    const transcribePollAttempts = Number.isFinite(transcribePollAttemptsRaw) && transcribePollAttemptsRaw > 0
        ? transcribePollAttemptsRaw
        : 20;
    const transcribePollIntervalMs = Number.isFinite(transcribePollIntervalRaw) && transcribePollIntervalRaw >= 250
        ? transcribePollIntervalRaw
        : 1200;
    const processingDefaults = {
        enableTrim: true,
        enableNoise: true,
        enableLoudness: true
    };
    let hiddenWords = Array.isArray(window.ll_recorder_data?.hidden_words)
        ? window.ll_recorder_data.hidden_words.slice()
        : [];

    images.forEach(item => {
        if (Array.isArray(item?.missing_types)) {
            item.missing_types = sortRecordingTypes(item.missing_types);
        }
        if (Array.isArray(item?.existing_types)) {
            item.existing_types = sortRecordingTypes(item.existing_types);
        }
    });

    if (images.length === 0 && !allowNewWords && hiddenWords.length === 0) return;

    const icons = {
        record: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="white"><circle cx="12" cy="12" r="8"/></svg>',
        stop: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="white"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>',
        check: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="white"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>',
        redo: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="white"><path d="M12 5V1L7 6l5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/></svg>',
        skip: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="white"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>',
        play: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>',
        hide: '<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" fill="none"><path d="M6 32 C14 26, 22 22, 32 22 C42 22, 50 26, 58 32 C50 38, 42 42, 32 42 C22 42, 14 38, 6 32Z" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="32" cy="32" r="7" fill="currentColor"/><path d="M16 16 L48 48" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round"/></svg>',
        unhide: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor"><path d="M12 5c5.8 0 9.8 4.6 11.3 6.8a1 1 0 0 1 0 1.1C21.8 15 17.8 19.5 12 19.5S2.2 15 0.7 12.9a1 1 0 0 1 0-1.1C2.2 9.6 6.2 5 12 5Zm0 2C7.5 7 4.2 10.4 2.8 12 4.2 13.6 7.5 17 12 17s7.8-3.4 9.2-5C19.8 10.4 16.5 7 12 7Zm0 2.2a2.8 2.8 0 1 1 0 5.6 2.8 2.8 0 0 1 0-5.6Z"/></svg>',
    };

    document.addEventListener('DOMContentLoaded', init);

    function buildSortLocales(rawLocale) {
        const value = String(rawLocale || '').trim().replace('_', '-');
        const locales = [];
        const pushLocale = function (locale) {
            const normalized = String(locale || '').trim();
            if (!normalized || locales.indexOf(normalized) !== -1) { return; }
            locales.push(normalized);
        };

        if (value) {
            pushLocale(value);
            const primary = value.split('-')[0];
            if (primary) {
                pushLocale(primary);
                if (primary.toLowerCase() === 'tr') {
                    pushLocale('tr-TR');
                }
            }
        }
        pushLocale('en-US');
        return locales;
    }

    function withTurkishSortLocales(baseLocales) {
        const combined = [];
        const pushLocale = function (value) {
            const normalized = String(value || '').trim();
            if (!normalized || combined.indexOf(normalized) !== -1) { return; }
            combined.push(normalized);
        };
        pushLocale('tr-TR');
        pushLocale('tr');
        (Array.isArray(baseLocales) ? baseLocales : []).forEach(pushLocale);
        return combined;
    }

    function textHasTurkishCharacters(value) {
        return /[çğıöşüÇĞİÖŞÜıİ]/.test(String(value || ''));
    }

    function localeTextCompare(left, right, options) {
        const a = String(left || '');
        const b = String(right || '');
        if (a === b) { return 0; }
        const opts = Object.assign({
            numeric: true,
            sensitivity: 'base'
        }, (options && typeof options === 'object') ? options : {});
        const locales = (textHasTurkishCharacters(a) || textHasTurkishCharacters(b))
            ? turkishSortLocales
            : sortLocales;

        try {
            return a.localeCompare(b, locales, opts);
        } catch (_) {
            try {
                return a.localeCompare(b, undefined, opts);
            } catch (_) {
                return a < b ? -1 : (a > b ? 1 : 0);
            }
        }
    }

    function init() {
        setupElements();
        hiddenWords = normalizeHiddenWords(hiddenWords);
        renderHiddenWordsList();
        setupCategorySelector();
        setupNewWordMode();
        if (images.length > 0) {
            loadImage(0);
        } else if (allowNewWords) {
            enterNewWordMode(false);
        } else {
            showComplete();
        }
        setupEventListeners();

        if (window.llRecorder && window.llRecorder.recordBtn) {
            window.llRecorder.recordBtn.innerHTML = icons.record;
        }
        if (window.llRecorder && window.llRecorder.newWordRecordBtn) {
            window.llRecorder.newWordRecordBtn.innerHTML = icons.record;
        }
        if (window.llRecorder && window.llRecorder.reviewRedoBtn) {
            window.llRecorder.reviewRedoBtn.innerHTML = icons.redo;
        }
        if (window.llRecorder && window.llRecorder.reviewSubmitBtn) {
            window.llRecorder.reviewSubmitBtn.innerHTML = icons.check;
        }
        if (window.llRecorder && window.llRecorder.newWordRedoBtn) {
            window.llRecorder.newWordRedoBtn.innerHTML = icons.redo;
        }
        if (window.llRecorder && window.llRecorder.hideBtn) {
            window.llRecorder.hideBtn.innerHTML = icons.hide;
        }
    }

    function setupElements() {
        window.llRecorder = {
            image: document.getElementById('ll-current-image'),
            imageContainer: document.querySelector('.ll-recording-image-container'),
            title: document.getElementById('ll-image-title'),
            recordBtn: document.getElementById('ll-record-btn'),
            hideBtn: document.getElementById('ll-hide-btn'),
            indicator: document.getElementById('ll-recording-indicator'),
            timer: document.getElementById('ll-recording-timer'),
            playbackControls: document.getElementById('ll-playback-controls'),
            playbackAudio: document.getElementById('ll-playback-audio'),
            redoBtn: document.getElementById('ll-redo-btn'),
            submitBtn: document.getElementById('ll-submit-btn'),
            skipBtn: document.getElementById('ll-skip-btn'),
            status: document.getElementById('ll-upload-status'),
            hiddenToggleBtn: document.getElementById('ll-hidden-words-toggle'),
            hiddenCount: document.getElementById('ll-hidden-words-count'),
            hiddenPanel: document.getElementById('ll-hidden-words-panel'),
            hiddenList: document.getElementById('ll-hidden-words-list'),
            hiddenEmpty: document.getElementById('ll-hidden-words-empty'),
            hiddenCloseBtn: document.getElementById('ll-hidden-words-close'),
            currentNum: document.querySelector('.ll-current-num'),
            totalNum: document.querySelector('.ll-total-num'),
            completeScreen: document.querySelector('.ll-recording-complete'),
            completedCount: document.querySelector('.ll-completed-count'),
            mainScreen: document.querySelector('.ll-recording-main'),
            categorySelect: document.getElementById('ll-category-select'),
            recordingTypeSelect: document.getElementById('ll-recording-type'),
            newWordToggle: document.getElementById('ll-new-word-toggle'),
            newWordPanel: document.querySelector('.ll-new-word-panel'),
            newWordAutoStatus: document.getElementById('ll-new-word-auto-status'),
            newWordAutoIcon: document.querySelector('#ll-new-word-auto-status .ll-new-word-auto-icon'),
            newWordAutoSpinner: document.querySelector('#ll-new-word-auto-status .ll-new-word-auto-spinner'),
            newWordAutoCancel: document.getElementById('ll-new-word-auto-cancel'),
            newWordCategory: document.getElementById('ll-new-word-category'),
            newWordCreateCategory: document.getElementById('ll-new-word-create-category'),
            newWordCategoryName: document.getElementById('ll-new-word-category-name'),
            newWordCreateFields: document.querySelector('.ll-new-word-create-fields'),
            newWordTypesWrap: document.querySelector('.ll-new-word-types'),
            newWordTextTarget: document.getElementById('ll-new-word-text-target'),
            newWordTextTranslation: document.getElementById('ll-new-word-text-translation'),
            newWordStartBtn: document.getElementById('ll-new-word-start'),
            newWordBackBtn: document.getElementById('ll-new-word-back'),
            newWordRecordBtn: document.getElementById('ll-new-word-record-btn'),
            newWordRecordingIndicator: document.getElementById('ll-new-word-recording-indicator'),
            newWordRecordingTimer: document.getElementById('ll-new-word-recording-timer'),
            newWordRecordingType: document.getElementById('ll-new-word-recording-type'),
            newWordRecordingTypeLabel: document.getElementById('ll-new-word-recording-type-label'),
            newWordPlaybackControls: document.getElementById('ll-new-word-playback-controls'),
            newWordPlaybackAudio: document.getElementById('ll-new-word-playback-audio'),
            newWordRedoBtn: document.getElementById('ll-new-word-redo-btn'),
            processingReview: document.getElementById('ll-recording-review'),
            reviewContainer: document.getElementById('ll-review-files-container'),
            reviewRedoBtn: document.getElementById('ll-review-redo'),
            reviewSubmitBtn: document.getElementById('ll-review-submit'),
        };
    }

    function isNewWordPanelActive() {
        return newWordMode && newWordUsingPanel;
    }

    function getActiveControls() {
        const el = window.llRecorder;
        const isNewWord = isNewWordPanelActive();
        if (isNewWord) {
            return {
                isNewWordPanel: true,
                recordBtn: el.newWordRecordBtn,
                indicator: el.newWordRecordingIndicator,
                timer: el.newWordRecordingTimer,
                playbackControls: el.newWordPlaybackControls,
                playbackAudio: el.newWordPlaybackAudio,
                redoBtn: el.newWordRedoBtn,
                skipBtn: null,
                hideBtn: null,
            };
        }
        return {
            isNewWordPanel: false,
            recordBtn: el.recordBtn,
            indicator: el.indicator,
            timer: el.timer,
            playbackControls: el.playbackControls,
            playbackAudio: el.playbackAudio,
            redoBtn: el.redoBtn,
            skipBtn: el.skipBtn,
            hideBtn: el.hideBtn,
        };
    }

    function setNewWordActionState(disabled) {
        const el = window.llRecorder;
        if (el.newWordStartBtn) el.newWordStartBtn.disabled = !!disabled;
        if (el.newWordBackBtn) el.newWordBackBtn.disabled = !!disabled;
        if (el.newWordRecordBtn) el.newWordRecordBtn.disabled = !!disabled;
        if (el.newWordRedoBtn) el.newWordRedoBtn.disabled = !!disabled;
    }

    function setupCategorySelector() {
        const el = window.llRecorder;
        if (el.categorySelect) {
            el.categorySelect.addEventListener('change', switchCategory);
        }
    }

    function setupNewWordMode() {
        if (!allowNewWords) return;
        const el = window.llRecorder;
        if (el.newWordCategory && lastNewWordCategory === null) {
            lastNewWordCategory = el.newWordCategory.value || 'uncategorized';
        }
        if (el.newWordToggle) {
            el.newWordToggle.addEventListener('click', () => enterNewWordMode(false));
        }
        if (el.newWordBackBtn) {
            el.newWordBackBtn.addEventListener('click', exitNewWordMode);
        }
        if (el.newWordStartBtn) {
            el.newWordStartBtn.addEventListener('click', handleNewWordSave);
        }
        if (el.newWordRecordBtn) {
            el.newWordRecordBtn.addEventListener('click', handleNewWordRecordToggle);
        }
        if (el.newWordRedoBtn) {
            el.newWordRedoBtn.addEventListener('click', redo);
        }
        if (el.newWordAutoCancel) {
            el.newWordAutoCancel.addEventListener('click', cancelNewWordAuto);
        }
        if (el.newWordCreateCategory) {
            el.newWordCreateCategory.addEventListener('change', toggleNewCategoryFields);
        }
        if (el.newWordCategory) {
            el.newWordCategory.addEventListener('change', () => {
                lastNewWordCategory = el.newWordCategory.value || lastNewWordCategory;
                updateNewWordRecordingTypeLabel();
                if (!el.newWordCreateCategory?.checked && el.newWordCategory.value) {
                    requestNewWordCategoryTypes(el.newWordCategory.value);
                }
            });
        }
        if (el.newWordTypesWrap) {
            el.newWordTypesWrap.addEventListener('change', event => {
                if (event.target && event.target.matches('input[type="checkbox"]')) {
                    updateNewWordRecordingTypeLabel();
                }
            });
        }
        if (el.newWordTextTarget) {
            el.newWordTextTarget.addEventListener('input', () => {
                el.newWordTextTarget.dataset.llManual = '1';
                clearNewWordFieldError(el.newWordTextTarget);
            });
            el.newWordTextTarget.addEventListener('blur', handleTargetBlur);
        }
        if (el.newWordTextTranslation) {
            el.newWordTextTranslation.addEventListener('input', () => {
                el.newWordTextTranslation.dataset.llManual = '1';
                clearNewWordFieldError(el.newWordTextTranslation);
            });
            el.newWordTextTranslation.addEventListener('blur', handleTranslationBlur);
        }
        if (el.newWordCategoryName) {
            el.newWordCategoryName.addEventListener('input', () => {
                clearNewWordFieldError(el.newWordCategoryName);
            });
        }
    }

    function decodeEntities(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.innerHTML = text;
        return div.textContent || div.innerText || '';
    }

    function sanitizeHideKey(raw) {
        if (raw === null || raw === undefined) return '';
        const key = String(raw).trim().toLowerCase().replace(/[^a-z0-9:_-]/g, '');
        if (!key) return '';
        if (key.startsWith('word:') || key.startsWith('image:') || key.startsWith('title:')) {
            return key;
        }
        return '';
    }

    function normalizeHideTitle(title) {
        const decoded = decodeEntities(title || '');
        const normalized = decoded
            .toLowerCase()
            .trim()
            .replace(/\s+/g, ' ')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
        if (normalized) return normalized;
        if (!decoded.trim()) return '';
        // Keep deterministic fallback for non-Latin titles.
        let hash = 0;
        for (let i = 0; i < decoded.length; i += 1) {
            hash = ((hash << 5) - hash + decoded.charCodeAt(i)) | 0;
        }
        return `t${Math.abs(hash)}`;
    }

    function buildHideKey(wordId, imageId, title) {
        const wid = Number.isFinite(wordId) ? Number(wordId) : parseInt(wordId, 10) || 0;
        const iid = Number.isFinite(imageId) ? Number(imageId) : parseInt(imageId, 10) || 0;
        if (wid > 0) return `word:${wid}`;
        if (iid > 0) return `image:${iid}`;
        const normalizedTitle = normalizeHideTitle(title || '');
        return normalizedTitle ? `title:${normalizedTitle}` : '';
    }

    function getItemHideKeys(item) {
        if (!item || typeof item !== 'object') return [];
        const keys = [];
        const explicit = sanitizeHideKey(item.hide_key);
        if (explicit) keys.push(explicit);

        const wordId = parseInt(item.word_id, 10) || 0;
        const imageId = parseInt(item.id || item.image_id, 10) || 0;
        if (wordId > 0) keys.push(`word:${wordId}`);
        if (imageId > 0) keys.push(`image:${imageId}`);

        const titleCandidates = [];
        if (typeof item.word_title === 'string' && item.word_title.trim()) {
            titleCandidates.push(item.word_title);
        }
        if (typeof item.title === 'string' && item.title.trim()) {
            titleCandidates.push(item.title);
        }
        titleCandidates.forEach(candidate => {
            const key = buildHideKey(0, 0, candidate);
            if (key) keys.push(key);
        });

        return Array.from(new Set(keys));
    }

    function normalizeHiddenWordEntry(entry) {
        if (!entry || typeof entry !== 'object') return null;
        const wordId = parseInt(entry.word_id, 10) || 0;
        const imageId = parseInt(entry.image_id, 10) || 0;
        const title = decodeEntities(entry.title || '');
        const categoryName = decodeEntities(entry.category_name || '');
        const categorySlug = String(entry.category_slug || '').trim();
        let key = sanitizeHideKey(entry.key || '');
        if (!key) {
            key = buildHideKey(wordId, imageId, title);
        }
        if (!key) return null;

        return {
            key,
            word_id: wordId,
            image_id: imageId,
            title: title || key.replace(/^title:/, '').replace(/-/g, ' '),
            category_name: categoryName,
            category_slug: categorySlug,
            hidden_at: String(entry.hidden_at || ''),
        };
    }

    function normalizeHiddenWords(list) {
        if (!Array.isArray(list)) return [];
        const byKey = new Map();
        list.forEach(entry => {
            const normalized = normalizeHiddenWordEntry(entry);
            if (!normalized) return;
            byKey.set(normalized.key, normalized);
        });
        const normalizedList = Array.from(byKey.values());
        normalizedList.sort((left, right) => {
            const leftTime = Date.parse(left.hidden_at || '') || 0;
            const rightTime = Date.parse(right.hidden_at || '') || 0;
            if (leftTime !== rightTime) return rightTime - leftTime;
            return localeTextCompare(left.title || '', right.title || '');
        });
        return normalizedList;
    }

    function setHiddenWordsFromServer(payload) {
        const maybeList = Array.isArray(payload)
            ? payload
            : (Array.isArray(payload?.hidden_words) ? payload.hidden_words : null);
        if (!maybeList) return;
        hiddenWords = normalizeHiddenWords(maybeList);
        renderHiddenWordsList();
    }

    function getPrimaryHideKeyForItem(item) {
        const keys = getItemHideKeys(item);
        return keys.length ? keys[0] : '';
    }

    function itemMatchesHideKeySet(item, keySet) {
        const keys = getItemHideKeys(item);
        return keys.some(key => keySet.has(key));
    }

    function renderHiddenWordsList() {
        const el = window.llRecorder;
        if (!el) return;

        if (el.hiddenCount) {
            el.hiddenCount.textContent = String(hiddenWords.length);
        }
        if (el.hiddenList) {
            el.hiddenList.innerHTML = '';
            hiddenWords.forEach(entry => {
                const row = document.createElement('li');
                row.className = 'll-hidden-word-item';

                const main = document.createElement('div');
                main.className = 'll-hidden-word-main';

                const title = document.createElement('span');
                title.className = 'll-hidden-word-title';
                title.textContent = entry.title || entry.key;
                main.appendChild(title);

                if (entry.category_name) {
                    const category = document.createElement('span');
                    category.className = 'll-hidden-word-category';
                    category.textContent = entry.category_name;
                    main.appendChild(category);
                }

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'll-btn ll-hidden-word-unhide';
                btn.dataset.hideKey = entry.key;
                btn.title = i18n.unhide || 'Unhide';
                btn.setAttribute('aria-label', i18n.unhide || 'Unhide');
                btn.innerHTML = icons.unhide;

                row.appendChild(main);
                row.appendChild(btn);
                el.hiddenList.appendChild(row);
            });
        }

        if (el.hiddenEmpty) {
            el.hiddenEmpty.style.display = hiddenWords.length ? 'none' : '';
        }
    }

    function setHiddenWordsPanelOpen(open) {
        const el = window.llRecorder;
        if (!el || !el.hiddenPanel || !el.hiddenToggleBtn) return;
        hiddenWordsPanelOpen = !!open;
        if (hiddenWordsPanelOpen) {
            el.hiddenPanel.removeAttribute('hidden');
        } else {
            el.hiddenPanel.setAttribute('hidden', 'hidden');
        }
        el.hiddenToggleBtn.setAttribute('aria-expanded', hiddenWordsPanelOpen ? 'true' : 'false');
    }

    function cloneImages(list) {
        return list.map(item => ({
            ...item,
            missing_types: Array.isArray(item.missing_types) ? item.missing_types.slice() : [],
            existing_types: Array.isArray(item.existing_types) ? item.existing_types.slice() : [],
        }));
    }

    function captureExistingState() {
        const el = window.llRecorder;
        return {
            images: cloneImages(images),
            index: currentImageIndex,
            category: el.categorySelect ? el.categorySelect.value : '',
            recordingTypeOptions: el.recordingTypeSelect
                ? Array.from(el.recordingTypeSelect.options).map(opt => ({
                    value: opt.value,
                    text: opt.textContent || opt.value,
                }))
                : [],
        };
    }

    function enterNewWordMode(skipSave) {
        if (!allowNewWords) return;
        setHiddenWordsPanelOpen(false);
        if (!skipSave && !savedExistingState) {
            savedExistingState = captureExistingState();
        }
        newWordMode = true;
        newWordStage = 'setup';
        newWordUsingPanel = true;
        newWordPrepared = false;
        newWordTranscriptionDone = false;
        newWordTranscriptionInFlight = false;
        newWordTranslationInFlight = false;
        newWordAutoCancelled = false;
        newWordLastSaved = { target: '', translation: '' };
        lastTranslationSource = '';
        activeRecordingControls = null;
        showNewWordPanel();
    }

    function exitNewWordMode() {
        const el = window.llRecorder;
        newWordMode = false;
        newWordStage = 'inactive';
        newWordUsingPanel = false;
        newWordPrepared = false;
        newWordTranscriptionDone = false;
        newWordTranscriptionInFlight = false;
        newWordTranslationInFlight = false;
        newWordAutoCancelled = false;
        newWordLastSaved = { target: '', translation: '' };
        activeRecordingControls = null;
        resetNewWordAutoState();
        syncProcessingReviewSlot();
        if (el.newWordPanel) el.newWordPanel.style.display = 'none';
        if (el.newWordToggle) el.newWordToggle.disabled = false;
        if (el.categorySelect) el.categorySelect.disabled = false;

        if (!savedExistingState) {
            if (el.mainScreen) el.mainScreen.style.display = images.length ? 'flex' : 'none';
            if (images.length > 0) {
                loadImage(0);
            } else {
                showComplete();
            }
            return;
        }

        const restored = savedExistingState;
        savedExistingState = null;

        const restoredImages = cloneImages(restored.images);
        window.ll_recorder_data.images = restoredImages;
        images.length = 0;
        images.push(...restoredImages);
        currentImageIndex = restored.index || 0;

        if (el.recordingTypeSelect) {
            el.recordingTypeSelect.innerHTML = '';
            restored.recordingTypeOptions.forEach(opt => {
                const option = document.createElement('option');
                option.value = opt.value;
                option.textContent = opt.text;
                el.recordingTypeSelect.appendChild(option);
            });
        }

        if (el.categorySelect && restored.category) {
            el.categorySelect.value = restored.category;
        }

        if (images.length > 0) {
            if (el.mainScreen) el.mainScreen.style.display = 'flex';
            loadImage(currentImageIndex);
        } else {
            if (el.mainScreen) el.mainScreen.style.display = 'none';
            showComplete();
        }
    }

    function showNewWordPanel() {
        const el = window.llRecorder;
        newWordUsingPanel = true;
        if (el.mainScreen) el.mainScreen.style.display = 'none';
        if (el.completeScreen) el.completeScreen.style.display = 'none';
        if (el.newWordPanel) el.newWordPanel.style.display = 'block';
        if (el.categorySelect) el.categorySelect.disabled = true;
        if (el.newWordToggle) el.newWordToggle.disabled = true;
        resetNewWordForm();
        syncProcessingReviewSlot();
        scrollToTop();
    }

    function resetNewWordForm() {
        const el = window.llRecorder;
        newWordPrepared = false;
        newWordTranscriptionDone = false;
        newWordTranscriptionInFlight = false;
        newWordTranslationInFlight = false;
        newWordAutoCancelled = false;
        activeRecordingControls = null;
        currentBlob = null;
        audioChunks = [];
        processingState = null;
        lastTranslationSource = '';
        newWordLastSaved = { target: '', translation: '' };
        resetNewWordAutoState();
        if (el.newWordTextTarget) {
            el.newWordTextTarget.value = '';
            delete el.newWordTextTarget.dataset.llManual;
            clearNewWordFieldError(el.newWordTextTarget);
        }
        if (el.newWordTextTranslation) {
            el.newWordTextTranslation.value = '';
            delete el.newWordTextTranslation.dataset.llManual;
            clearNewWordFieldError(el.newWordTextTranslation);
        }
        if (el.newWordCategoryName) {
            el.newWordCategoryName.value = '';
            clearNewWordFieldError(el.newWordCategoryName);
        }
        if (el.newWordCreateCategory) {
            el.newWordCreateCategory.checked = false;
        }
        if (el.newWordCreateFields) {
            el.newWordCreateFields.style.display = 'none';
        }
        if (el.newWordCategory) {
            el.newWordCategory.disabled = false;
            const options = Array.from(el.newWordCategory.options).map(opt => opt.value);
            const preferred = lastNewWordCategory || 'uncategorized';
            if (options.includes(preferred)) {
                el.newWordCategory.value = preferred;
            } else if (options.includes('uncategorized')) {
                el.newWordCategory.value = 'uncategorized';
            } else if (options.length > 0) {
                el.newWordCategory.value = options[0];
            }
        }
        if (el.newWordTypesWrap) {
            const checks = el.newWordTypesWrap.querySelectorAll('input[type="checkbox"]');
            checks.forEach(chk => {
                chk.checked = (chk.value === 'isolation');
            });
        }
        if (el.newWordRecordBtn) {
            el.newWordRecordBtn.style.display = 'inline-flex';
            el.newWordRecordBtn.classList.remove('recording');
            el.newWordRecordBtn.disabled = false;
            el.newWordRecordBtn.innerHTML = icons.record;
        }
        if (el.newWordRecordingIndicator) {
            el.newWordRecordingIndicator.style.display = 'none';
        }
        if (el.newWordRecordingTimer) {
            el.newWordRecordingTimer.textContent = '0:00';
        }
        if (el.newWordRecordingType) {
            el.newWordRecordingType.style.display = 'none';
        }
        if (el.newWordRecordingTypeLabel) {
            el.newWordRecordingTypeLabel.textContent = '';
        }
        if (el.newWordPlaybackControls) {
            el.newWordPlaybackControls.style.display = 'none';
        }
        if (el.newWordPlaybackAudio) {
            el.newWordPlaybackAudio.removeAttribute('src');
        }
        if (el.newWordStartBtn) el.newWordStartBtn.disabled = true;
        if (el.newWordBackBtn) el.newWordBackBtn.disabled = false;
        updateNewWordRecordingTypeLabel();
    }

    function clearNewWordTextFields() {
        const el = window.llRecorder;
        if (el.newWordTextTarget) {
            el.newWordTextTarget.value = '';
            delete el.newWordTextTarget.dataset.llManual;
            clearNewWordFieldError(el.newWordTextTarget);
        }
        if (el.newWordTextTranslation) {
            el.newWordTextTranslation.value = '';
            delete el.newWordTextTranslation.dataset.llManual;
            clearNewWordFieldError(el.newWordTextTranslation);
        }
        lastTranslationSource = '';
    }

    function toggleNewCategoryFields() {
        const el = window.llRecorder;
        const enabled = !!el.newWordCreateCategory?.checked;
        if (el.newWordCreateFields) {
            el.newWordCreateFields.style.display = enabled ? 'block' : 'none';
        }
        if (el.newWordCategory) {
            el.newWordCategory.disabled = enabled;
        }
        if (enabled && el.newWordCategoryName) {
            el.newWordCategoryName.focus();
        } else if (el.newWordCategoryName) {
            clearNewWordFieldError(el.newWordCategoryName);
        }
        updateNewWordRecordingTypeLabel();
    }

    function markNewWordFieldError(field) {
        if (!field) return;
        field.classList.add('ll-field-error');
        field.setAttribute('aria-invalid', 'true');
        field.focus();
    }

    function clearNewWordFieldError(field) {
        if (!field) return;
        field.classList.remove('ll-field-error');
        field.removeAttribute('aria-invalid');
    }

    function setNewWordTextFieldsDisabled(disabled) {
        const el = window.llRecorder;
        if (el.newWordTextTarget) el.newWordTextTarget.disabled = !!disabled;
        if (el.newWordTextTranslation) el.newWordTextTranslation.disabled = !!disabled;
    }

    function clearNewWordAutoTimer() {
        if (newWordAutoHideTimer) {
            clearTimeout(newWordAutoHideTimer);
            newWordAutoHideTimer = null;
        }
    }

    function hideNewWordAutoStatus() {
        const el = window.llRecorder;
        if (!el.newWordAutoStatus) return;
        el.newWordAutoStatus.style.display = 'none';
        el.newWordAutoStatus.classList.remove('is-loading', 'is-error', 'is-success');
        el.newWordAutoStatus.removeAttribute('aria-label');
        el.newWordAutoStatus.removeAttribute('title');
        el.newWordAutoStatus.setAttribute('aria-busy', 'false');
    }

    function updateNewWordAutoUI() {
        const el = window.llRecorder;
        if (!el.newWordAutoStatus) return;

        const busy = (newWordTranscriptionInFlight || newWordTranslationInFlight) && !newWordAutoCancelled;
        if (!busy) {
            setNewWordTextFieldsDisabled(false);
            if (
                !newWordTranscriptionInFlight
                && !newWordTranslationInFlight
                && !el.newWordAutoStatus.classList.contains('is-error')
                && !el.newWordAutoStatus.classList.contains('is-success')
            ) {
                hideNewWordAutoStatus();
            }
            return;
        }

        clearNewWordAutoTimer();
        const label = newWordTranscriptionInFlight
            ? (i18n.transcribing || 'Transcribing...')
            : (i18n.translating || 'Translating...');

        el.newWordAutoStatus.style.display = 'inline-flex';
        el.newWordAutoStatus.classList.add('is-loading');
        el.newWordAutoStatus.classList.remove('is-error', 'is-success');
        el.newWordAutoStatus.setAttribute('aria-busy', 'true');
        if (label) {
            el.newWordAutoStatus.setAttribute('aria-label', label);
            el.newWordAutoStatus.title = label;
        }
        if (el.newWordAutoSpinner) el.newWordAutoSpinner.style.display = 'inline-block';
        if (el.newWordAutoCancel) el.newWordAutoCancel.style.display = 'inline-flex';
        setNewWordTextFieldsDisabled(true);
    }

    function flashNewWordAutoStatus(state, label, timeout = 2400) {
        const el = window.llRecorder;
        if (!el.newWordAutoStatus) return;

        clearNewWordAutoTimer();
        el.newWordAutoStatus.style.display = 'inline-flex';
        el.newWordAutoStatus.classList.remove('is-loading', 'is-error', 'is-success');
        if (state) el.newWordAutoStatus.classList.add(`is-${state}`);
        el.newWordAutoStatus.setAttribute('aria-busy', 'false');
        if (label) {
            el.newWordAutoStatus.setAttribute('aria-label', label);
            el.newWordAutoStatus.title = label;
        }
        if (el.newWordAutoSpinner) el.newWordAutoSpinner.style.display = 'none';
        if (el.newWordAutoCancel) el.newWordAutoCancel.style.display = 'none';

        if (timeout > 0) {
            newWordAutoHideTimer = setTimeout(() => {
                if (!newWordTranscriptionInFlight && !newWordTranslationInFlight) {
                    hideNewWordAutoStatus();
                }
            }, timeout);
        }
    }

    function cancelNewWordAuto() {
        newWordAutoCancelled = true;
        if (newWordTranscriptionInFlight) {
            newWordTranscriptionDone = true;
        }
        if (newWordTranscribeAbort) {
            newWordTranscribeAbort.abort();
            newWordTranscribeAbort = null;
        }
        if (newWordTranslateAbort) {
            newWordTranslateAbort.abort();
            newWordTranslateAbort = null;
        }
        newWordTranscriptionInFlight = false;
        newWordTranslationInFlight = false;
        updateNewWordAutoUI();
    }

    function resetNewWordAutoState() {
        clearNewWordAutoTimer();
        if (newWordTranscribeAbort) {
            newWordTranscribeAbort.abort();
            newWordTranscribeAbort = null;
        }
        if (newWordTranslateAbort) {
            newWordTranslateAbort.abort();
            newWordTranslateAbort = null;
        }
        hideNewWordAutoStatus();
        setNewWordTextFieldsDisabled(false);
    }

    function scrollToTop() {
        const prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const behavior = prefersReduced ? 'auto' : 'smooth';
        try {
            window.scrollTo({ top: 0, behavior });
        } catch (_) {
            document.documentElement.scrollTop = 0;
            document.body.scrollTop = 0;
        }
    }

    function getReviewSlots() {
        return {
            main: document.getElementById('ll-recording-review-slot'),
            newWord: document.getElementById('ll-new-word-review-slot'),
        };
    }

    function toggleReviewSubmitVisibility(hide) {
        const el = window.llRecorder;
        if (!el.reviewSubmitBtn) return;
        el.reviewSubmitBtn.style.display = hide ? 'none' : '';
    }

    function moveProcessingReviewToNewWordSlot() {
        const el = window.llRecorder;
        if (!el?.processingReview) return;
        const slots = getReviewSlots();
        if (!slots.newWord) return;
        if (!slots.newWord.contains(el.processingReview)) {
            slots.newWord.appendChild(el.processingReview);
        }
        toggleReviewSubmitVisibility(true);
    }

    function moveProcessingReviewToMainSlot() {
        const el = window.llRecorder;
        if (!el?.processingReview) return;
        const slots = getReviewSlots();
        if (!slots.main) return;
        if (!slots.main.contains(el.processingReview)) {
            slots.main.appendChild(el.processingReview);
        }
        toggleReviewSubmitVisibility(false);
    }

    function syncProcessingReviewSlot() {
        if (newWordMode && newWordUsingPanel) {
            moveProcessingReviewToNewWordSlot();
        } else {
            moveProcessingReviewToMainSlot();
        }
    }

    async function handleNewWordRecordToggle() {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            stopRecording();
            return;
        }
        const shouldSuppressScroll = !newWordPrepared;
        if (shouldSuppressScroll) {
            suppressNextScroll = true;
        }
        const ready = await prepareNewWordRecording({ keepPanel: true });
        if (!ready) {
            if (shouldSuppressScroll) {
                suppressNextScroll = false;
            }
            return;
        }
        await startRecording();
    }

    async function handleNewWordSave() {
        submitAndNext();
    }

    function handleTargetBlur() {
        const el = window.llRecorder;
        if (!el.newWordTextTarget) return;
        if (newWordTranscriptionInFlight || newWordTranslationInFlight) return;
        const text = (el.newWordTextTarget.value || '').trim();
        if (newWordPrepared) {
            maybeUpdateNewWordText();
        }
        if (!text || !hasDeepl) return;
        const translationField = el.newWordTextTranslation;
        if (!translationField) return;
        if ((translationField.value || '').trim() !== '') return;
        if (translationField.dataset.llManual === '1') return;
        if (text === lastTranslationSource) return;
        maybeTranslateTargetText(text);
    }

    function handleTranslationBlur() {
        if (newWordTranscriptionInFlight || newWordTranslationInFlight) return;
        if (newWordPrepared) {
            maybeUpdateNewWordText();
        }
    }

    async function maybeUpdateNewWordText(force) {
        if (!newWordPrepared) return true;
        const el = window.llRecorder;
        const targetText = (el.newWordTextTarget?.value || '').trim();
        const translationText = (el.newWordTextTranslation?.value || '').trim();
        if (!force && targetText === newWordLastSaved.target && translationText === newWordLastSaved.translation) {
            return true;
        }

        const wordId = images[currentImageIndex]?.word_id || images[0]?.word_id || 0;
        if (!wordId) {
            flashNewWordAutoStatus('error', i18n.new_word_failed || 'New word setup failed:');
            return false;
        }

        const formData = new FormData();
        formData.append('action', 'll_update_new_word_text');
        formData.append('nonce', nonce);
        formData.append('word_id', wordId);
        formData.append('word_text_target', targetText);
        formData.append('word_text_translation', translationText);

        try {
            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error(i18n.invalid_response || 'Server returned invalid response format');
            }
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.data || data.message || 'Failed to update word text');
            }
            const responseData = data.data || {};
            const postTitle = typeof responseData.post_title === 'string' ? responseData.post_title : '';
            const savedTranslation = typeof responseData.word_translation === 'string' ? responseData.word_translation : '';
            const storeInTitle = typeof responseData.store_in_title === 'boolean' ? responseData.store_in_title : null;

            newWordLastSaved = { target: targetText, translation: translationText };
            applyNewWordTextToState({
                targetText,
                translationText,
                postTitle,
                savedTranslation,
                storeInTitle
            });
            refreshCurrentImageDisplay();
            return true;
        } catch (err) {
            flashNewWordAutoStatus('error', (i18n.new_word_failed || 'New word setup failed:') + ' ' + (err.message || ''));
            return false;
        }
    }

    function applyNewWordTextToState(payload) {
        const img = images[currentImageIndex];
        if (!img) return;

        const targetText = payload?.targetText || '';
        const translationText = payload?.translationText || '';
        const postTitle = payload?.postTitle || '';
        const savedTranslation = payload?.savedTranslation;
        const storeInTitle = typeof payload?.storeInTitle === 'boolean' ? payload.storeInTitle : null;

        const resolvedTitle = postTitle
            || (storeInTitle === false ? (translationText || targetText) : (targetText || translationText));

        if (resolvedTitle) {
            img.title = resolvedTitle;
            img.word_title = resolvedTitle;
        }

        if (typeof savedTranslation === 'string' && savedTranslation !== '') {
            img.word_translation = savedTranslation;
        } else if (typeof translationText === 'string') {
            img.word_translation = translationText;
        }

        img.use_word_display = true;
    }

    function getBlobExtension(mimeType) {
        const blobTypeRaw = (mimeType || '').toLowerCase();
        const blobType = blobTypeRaw.split(';', 1)[0].trim();

        // Prefer container hints over codec hints so "audio/webm;codecs=pcm"
        // still uploads as .webm instead of being mislabeled as .wav.
        if (blobType.includes('webm')) return '.webm';
        if (blobType.includes('ogg')) return '.ogg';
        if (blobType.includes('wav') || blobType.includes('wave')) return '.wav';
        if (blobType.includes('mpeg') || blobType.includes('mp3')) return '.mp3';
        if (blobType.includes('m4a')) return '.m4a';
        if (blobType.includes('mp4')) return '.mp4';
        if (blobType.includes('aac')) return '.aac';
        if (blobType.includes('pcm')) return '.wav';
        return '.webm';
    }

    function createAbortError() {
        const err = new Error('Request aborted');
        err.name = 'AbortError';
        return err;
    }

    function waitForDuration(ms, signal) {
        if (!ms || ms <= 0) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            let settled = false;
            const onAbort = () => {
                if (settled) return;
                settled = true;
                clearTimeout(timer);
                if (signal) {
                    signal.removeEventListener('abort', onAbort);
                }
                reject(createAbortError());
            };
            const timer = setTimeout(() => {
                if (settled) return;
                settled = true;
                if (signal) {
                    signal.removeEventListener('abort', onAbort);
                }
                resolve();
            }, ms);

            if (signal) {
                if (signal.aborted) {
                    onAbort();
                    return;
                }
                signal.addEventListener('abort', onAbort, { once: true });
            }
        });
    }

    async function pollNewWordTranscription(transcriptId, wordsetIds, wordsetLegacy, signal) {
        if (!transcriptId) {
            throw new Error(i18n.transcription_timeout || 'Transcription is still processing. Please try again in a moment.');
        }

        for (let attempt = 0; attempt < transcribePollAttempts; attempt++) {
            if (signal?.aborted || newWordAutoCancelled) {
                throw createAbortError();
            }

            if (attempt > 0) {
                await waitForDuration(transcribePollIntervalMs, signal);
            }

            const formData = new FormData();
            formData.append('action', 'll_transcribe_recording_status');
            formData.append('nonce', nonce);
            formData.append('transcript_id', transcriptId);
            formData.append('wordset_ids', JSON.stringify(wordsetIds));
            formData.append('wordset', wordsetLegacy);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
                signal
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error(i18n.invalid_response || 'Server returned invalid response format');
            }

            const data = await response.json();
            if (!data.success) {
                const message = data.data?.message || data.data || data.message || 'Transcription failed';
                throw new Error(message);
            }

            const payload = data.data || {};
            if (!payload.pending) {
                return payload;
            }
        }

        throw new Error(i18n.transcription_timeout || 'Transcription is still processing. Please try again in a moment.');
    }

    async function maybeTranslateTargetText(text) {
        const el = window.llRecorder;
        if (!hasDeepl || !text) return;
        if (newWordTranslationInFlight) return;

        newWordAutoCancelled = false;
        newWordTranslationInFlight = true;
        if (newWordTranslateAbort) {
            newWordTranslateAbort.abort();
        }
        const translateController = new AbortController();
        newWordTranslateAbort = translateController;
        updateNewWordAutoUI();

        const wordsetIds = Array.isArray(window.ll_recorder_data?.wordset_ids) ? window.ll_recorder_data.wordset_ids : [];
        const wordsetLegacy = window.ll_recorder_data?.wordset || '';

        const formData = new FormData();
        formData.append('action', 'll_translate_recording_text');
        formData.append('nonce', nonce);
        formData.append('text', text);
        formData.append('wordset_ids', JSON.stringify(wordsetIds));
        formData.append('wordset', wordsetLegacy);

        try {
            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
                signal: translateController.signal
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error(i18n.invalid_response || 'Server returned invalid response format');
            }
            const data = await response.json();
            if (!data.success) {
                const message = data.data?.message || data.data || data.message || 'Translation failed';
                throw new Error(message);
            }

            if (newWordAutoCancelled || translateController.signal.aborted) {
                return;
            }

            const translation = data.data?.translation || '';
            if (translation && el.newWordTextTranslation) {
                if ((el.newWordTextTranslation.value || '').trim() === '' && el.newWordTextTranslation.dataset.llManual !== '1') {
                    el.newWordTextTranslation.value = translation;
                    el.newWordTextTranslation.dataset.llManual = '0';
                }
            }
            lastTranslationSource = text;
            if (newWordPrepared) {
                await maybeUpdateNewWordText(true);
            }
        } catch (err) {
            if (err && err.name === 'AbortError') {
                return;
            }
            flashNewWordAutoStatus('error', (i18n.translation_failed || 'Translation failed:') + ' ' + (err.message || ''));
        } finally {
            newWordTranslationInFlight = false;
            if (newWordTranslateAbort === translateController) {
                newWordTranslateAbort = null;
            }
            updateNewWordAutoUI();
        }
    }

    async function maybeTranscribeNewWordRecording(blob) {
        if (!blob || !isNewWordPanelActive() || newWordTranscriptionDone || newWordTranscriptionInFlight) return;
        const el = window.llRecorder;

        if (!hasAssemblyAI) {
            newWordTranscriptionDone = true;
            return;
        }

        newWordAutoCancelled = false;
        newWordTranscriptionInFlight = true;
        if (newWordTranscribeAbort) {
            newWordTranscribeAbort.abort();
        }
        const transcribeController = new AbortController();
        newWordTranscribeAbort = transcribeController;
        updateNewWordAutoUI();

        const wordsetIds = Array.isArray(window.ll_recorder_data?.wordset_ids) ? window.ll_recorder_data.wordset_ids : [];
        const wordsetLegacy = window.ll_recorder_data?.wordset || '';
        const filename = `recording${getBlobExtension(blob?.type || '')}`;

        const formData = new FormData();
        formData.append('action', 'll_transcribe_recording');
        formData.append('nonce', nonce);
        formData.append('audio', blob, filename);
        formData.append('wordset_ids', JSON.stringify(wordsetIds));
        formData.append('wordset', wordsetLegacy);

        try {
            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
                signal: transcribeController.signal
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error(i18n.invalid_response || 'Server returned invalid response format');
            }
            const data = await response.json();
            if (!data.success) {
                const message = data.data?.message || data.data || data.message || 'Transcription failed';
                throw new Error(message);
            }

            let resultData = data.data || {};
            if (resultData.pending) {
                resultData = await pollNewWordTranscription(
                    resultData.transcript_id || '',
                    wordsetIds,
                    wordsetLegacy,
                    transcribeController.signal
                );
            }

            if (newWordAutoCancelled || transcribeController.signal.aborted) {
                return;
            }

            const transcript = (resultData.transcript || '').trim();
            const translation = (resultData.translation || '').trim();

            if (transcript && el.newWordTextTarget) {
                if ((el.newWordTextTarget.value || '').trim() === '' || el.newWordTextTarget.dataset.llManual !== '1') {
                    el.newWordTextTarget.value = transcript;
                    el.newWordTextTarget.dataset.llManual = '0';
                }
            }

            if (translation && el.newWordTextTranslation) {
                if ((el.newWordTextTranslation.value || '').trim() === '' || el.newWordTextTranslation.dataset.llManual !== '1') {
                    el.newWordTextTranslation.value = translation;
                    el.newWordTextTranslation.dataset.llManual = '0';
                }
            }

            if (newWordPrepared) {
                await maybeUpdateNewWordText(true);
            }

            newWordTranscriptionDone = true;
        } catch (err) {
            if (err && err.name === 'AbortError') {
                return;
            }
            flashNewWordAutoStatus('error', (i18n.transcription_failed || 'Transcription failed:') + ' ' + (err.message || ''));
        } finally {
            newWordTranscriptionInFlight = false;
            if (newWordTranscribeAbort === transcribeController) {
                newWordTranscribeAbort = null;
            }
            updateNewWordAutoUI();
        }
    }

    function getRecordingTypeSlug(item) {
        if (typeof item === 'string') {
            return item;
        }
        return item?.slug || '';
    }

    function getRecordingTypeOrderIndex(slug) {
        if (!slug) return Number.MAX_SAFE_INTEGER;
        const idx = recordingTypeOrder.indexOf(slug);
        return idx === -1 ? Number.MAX_SAFE_INTEGER : idx;
    }

    function compareRecordingTypes(left, right) {
        const leftSlug = getRecordingTypeSlug(left);
        const rightSlug = getRecordingTypeSlug(right);
        const orderDiff = getRecordingTypeOrderIndex(leftSlug) - getRecordingTypeOrderIndex(rightSlug);
        if (orderDiff !== 0) {
            return orderDiff;
        }
        return localeTextCompare(leftSlug, rightSlug);
    }

    function sortRecordingTypes(list) {
        if (!Array.isArray(list)) return [];
        const sorted = list.slice().sort(compareRecordingTypes);
        const seen = new Set();
        const deduped = [];
        sorted.forEach(item => {
            const slug = getRecordingTypeSlug(item);
            if (!slug || seen.has(slug)) return;
            seen.add(slug);
            deduped.push(item);
        });
        return deduped;
    }

    function getRecordingTypeIcon(slug) {
        if (slug && recordingTypeIcons[slug]) {
            return recordingTypeIcons[slug];
        }
        return recordingTypeIcons.default || '';
    }

    function getRecordingTypeLabel(slug, typeList) {
        if (!slug) return '';
        const types = Array.isArray(typeList) && typeList.length
            ? typeList
            : (Array.isArray(window.ll_recorder_data?.recording_types)
                ? window.ll_recorder_data.recording_types
                : []);
        const match = types.find(item => getRecordingTypeSlug(item) === slug);
        if (typeof match === 'object' && match) {
            return match.name || match.slug || slug;
        }
        if (typeof match === 'string') {
            return match;
        }
        return slug
            .split(/[-_]+/)
            .filter(Boolean)
            .map(part => part.charAt(0).toUpperCase() + part.slice(1))
            .join(' ');
    }

    function getRecordingTypeDisplay(slug, typeList) {
        const label = getRecordingTypeLabel(slug, typeList);
        const icon = getRecordingTypeIcon(slug);
        return {
            icon,
            label,
            text: icon ? `${icon} ${label}` : label
        };
    }

    function getNewWordCreateCategoryTypes() {
        const el = window.llRecorder;
        if (!el?.newWordTypesWrap) return { types: [], selected: [] };
        const inputs = Array.from(el.newWordTypesWrap.querySelectorAll('input[type="checkbox"]'));
        const types = inputs
            .map(input => {
                const name = (input.dataset.typeName || '').trim();
                return { slug: input.value, name };
            })
            .filter(type => type.slug);
        const selected = inputs
            .filter(input => input.checked)
            .map(input => input.value)
            .filter(Boolean);
        return { types, selected };
    }

    async function requestNewWordCategoryTypes(categorySlug) {
        if (!ajaxUrl || !nonce || !categorySlug) return;
        if (newWordCategoryTypeCache[categorySlug]) return;
        if (newWordCategoryTypeAbort) {
            newWordCategoryTypeAbort.abort();
        }
        const controller = new AbortController();
        newWordCategoryTypeAbort = controller;

        const formData = new FormData();
        formData.append('action', 'll_get_recording_types_for_category');
        formData.append('nonce', nonce);
        formData.append('category', categorySlug);
        formData.append('include_types', window.ll_recorder_data?.include_types || '');
        formData.append('exclude_types', window.ll_recorder_data?.exclude_types || '');

        try {
            const response = await fetch(ajaxUrl, { method: 'POST', body: formData, signal: controller.signal });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error(i18n.invalid_response || 'Server returned invalid response format');
            }
            const data = await response.json();
            if (!data.success) {
                return;
            }
            const types = Array.isArray(data.data?.recording_types) ? data.data.recording_types : [];
            newWordCategoryTypeCache[categorySlug] = sortRecordingTypes(types);
            updateNewWordRecordingTypeLabel();
        } catch (err) {
            if (err && err.name === 'AbortError') {
                return;
            }
        } finally {
            if (newWordCategoryTypeAbort === controller) {
                newWordCategoryTypeAbort = null;
            }
        }
    }

    function updateNewWordRecordingTypeLabel() {
        const el = window.llRecorder;
        if (!el?.newWordRecordingType || !el.newWordRecordingTypeLabel) return;

        if (!isNewWordPanelActive()) {
            el.newWordRecordingType.style.display = 'none';
            return;
        }

        let slug = '';
        let labelText = '';

        if (newWordPrepared) {
            const img = images[currentImageIndex];
            const missing = Array.isArray(img?.missing_types) ? img.missing_types : [];
            slug = el.recordingTypeSelect?.value || missing[0] || '';
            labelText = getRecordingTypeDisplay(slug).text;
        } else if (el.newWordCreateCategory?.checked) {
            const { types, selected } = getNewWordCreateCategoryTypes();
            const ordered = selected.length ? sortRecordingTypes(selected) : sortRecordingTypes(types);
            const first = ordered[0];
            slug = typeof first === 'string' ? first : first?.slug || '';
            labelText = getRecordingTypeDisplay(slug, types).text;
        } else {
            const categorySlug = el.newWordCategory?.value || lastNewWordCategory || 'uncategorized';
            const cached = newWordCategoryTypeCache[categorySlug];
            if (Array.isArray(cached) && cached.length) {
                const ordered = sortRecordingTypes(cached);
                const first = ordered[0];
                slug = typeof first === 'string' ? first : first?.slug || '';
                labelText = getRecordingTypeDisplay(slug, cached).text;
            } else if (categorySlug) {
                requestNewWordCategoryTypes(categorySlug);
            }
        }

        if (!labelText) {
            el.newWordRecordingType.style.display = 'none';
            return;
        }

        el.newWordRecordingTypeLabel.textContent = labelText;
        el.newWordRecordingType.style.display = 'inline-flex';
    }

    function updateRecordingTypeOptions(types) {
        const el = window.llRecorder;
        if (!el.recordingTypeSelect) return;
        el.recordingTypeSelect.innerHTML = '';
        const orderedTypes = sortRecordingTypes(types);
        if (orderedTypes.length === 0) {
            updateNewWordRecordingTypeLabel();
            return;
        }
        if (orderedTypes.length && typeof orderedTypes[0] === 'object') {
            window.ll_recorder_data.recording_types = orderedTypes;
        }
        orderedTypes.forEach(type => {
            const slug = typeof type === 'string' ? type : type.slug;
            if (!slug) return;
            const display = getRecordingTypeDisplay(slug, orderedTypes);
            const option = document.createElement('option');
            option.value = slug;
            option.textContent = display.text || display.label || slug;
            el.recordingTypeSelect.appendChild(option);
        });
        updateNewWordRecordingTypeLabel();
    }

    async function prepareNewWordRecording(options = {}) {
        const { keepPanel = false } = options;
        const el = window.llRecorder;
        if (!el.newWordPanel || !allowNewWords) return false;
        if (newWordPrepared) return true;

        const createCategory = !!el.newWordCreateCategory?.checked;
        const newCategoryName = (el.newWordCategoryName?.value || '').trim();
        if (createCategory && !newCategoryName) {
            markNewWordFieldError(el.newWordCategoryName);
            flashNewWordAutoStatus('error', i18n.new_word_missing_category || 'Enter a category name or disable "Create new category".');
            return false;
        }

        const targetText = (el.newWordTextTarget?.value || '').trim();
        const translationText = (el.newWordTextTranslation?.value || '').trim();
        const category = el.newWordCategory?.value || 'uncategorized';
        lastNewWordCategory = category;

        if (el.newWordStartBtn) el.newWordStartBtn.disabled = true;
        if (el.newWordBackBtn) el.newWordBackBtn.disabled = true;
        if (el.newWordRecordBtn) el.newWordRecordBtn.disabled = true;

        const wordsetIds = Array.isArray(window.ll_recorder_data?.wordset_ids) ? window.ll_recorder_data.wordset_ids : [];
        const wordsetLegacy = window.ll_recorder_data?.wordset || '';
        const includeTypes = window.ll_recorder_data?.include_types || '';
        const excludeTypes = window.ll_recorder_data?.exclude_types || '';

        const formData = new FormData();
        formData.append('action', 'll_prepare_new_word_recording');
        formData.append('nonce', nonce);
        formData.append('category', category);
        formData.append('create_category', createCategory ? '1' : '0');
        formData.append('new_category_name', newCategoryName);
        formData.append('word_text_target', targetText);
        formData.append('word_text_translation', translationText);
        formData.append('wordset_ids', JSON.stringify(wordsetIds));
        formData.append('wordset', wordsetLegacy);
        formData.append('include_types', includeTypes);
        formData.append('exclude_types', excludeTypes);

        if (createCategory && el.newWordTypesWrap) {
            const selected = Array.from(el.newWordTypesWrap.querySelectorAll('input[type="checkbox"]:checked'))
                .map(input => input.value)
                .filter(Boolean);
            selected.forEach(slug => {
                formData.append('new_category_types[]', slug);
            });
        }

        try {
            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error(i18n.invalid_response || 'Server returned invalid response format');
            }
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.data || data.message || 'Failed to prepare new word');
            }

            const word = data.data?.word;
            if (!word) {
                throw new Error('Missing word data');
            }
            const recordingTypes = sortRecordingTypes(data.data?.recording_types || []);
            if (Array.isArray(word.missing_types)) {
                word.missing_types = sortRecordingTypes(word.missing_types);
            }
            if (Array.isArray(word.existing_types)) {
                word.existing_types = sortRecordingTypes(word.existing_types);
            }

            if (data.data?.category?.slug && el.newWordCategory) {
                const slug = data.data.category.slug;
                const name = data.data.category.name || slug;
                const exists = Array.from(el.newWordCategory.options).some(opt => opt.value === slug);
                if (!exists) {
                    const opt = document.createElement('option');
                    opt.value = slug;
                    opt.textContent = name;
                    el.newWordCategory.appendChild(opt);
                }
                el.newWordCategory.value = slug;
                lastNewWordCategory = slug;
            }

            window.ll_recorder_data.images = [word];
            images.length = 0;
            images.push(word);
            currentImageIndex = 0;
            updateRecordingTypeOptions(recordingTypes);

            newWordPrepared = true;
            newWordStage = 'recording';
            newWordUsingPanel = !!keepPanel;
            newWordTranscriptionDone = false;
            newWordTranscriptionInFlight = false;
            newWordLastSaved = { target: targetText, translation: translationText };

            if (el.categorySelect) el.categorySelect.disabled = true;
            if (el.newWordToggle) el.newWordToggle.disabled = true;

            if (keepPanel) {
                if (el.newWordPanel) el.newWordPanel.style.display = 'block';
                if (el.mainScreen) el.mainScreen.style.display = 'none';
            } else {
                if (el.newWordPanel) el.newWordPanel.style.display = 'none';
                if (el.mainScreen) el.mainScreen.style.display = 'flex';
            }
            syncProcessingReviewSlot();

            loadImage(0);
            return true;
        } catch (err) {
            flashNewWordAutoStatus('error', (i18n.new_word_failed || 'New word setup failed:') + ' ' + (err.message || ''));
            return false;
        } finally {
            if (el.newWordStartBtn) el.newWordStartBtn.disabled = true;
            if (el.newWordBackBtn) el.newWordBackBtn.disabled = false;
            if (el.newWordRecordBtn) el.newWordRecordBtn.disabled = false;
        }
    }

    // Fit text inside its container for text-only cards
    function fitTextToContainer(el, attempt = 0) {
        if (!el) return;
        const card = el.closest('.flashcard-container');
        if (!card) return;

        const rect = card.getBoundingClientRect();
        if (rect.width <= 5 || rect.height <= 5) {
            if (attempt < 4) {
                requestAnimationFrame(() => fitTextToContainer(el, attempt + 1));
            }
            return;
        }

        const computed = getComputedStyle(el);
        const paddingX = (parseFloat(computed.paddingLeft) || 0) + (parseFloat(computed.paddingRight) || 0);
        const paddingY = (parseFloat(computed.paddingTop) || 0) + (parseFloat(computed.paddingBottom) || 0);

        // Match quiz sizing: measure available box and shrink until it fits
        const boxH = Math.max(1, rect.height - paddingY);
        const boxW = Math.max(1, rect.width - paddingX);
        const fontFamily = (window.LLFlashcards && LLFlashcards.Util && typeof LLFlashcards.Util.measureTextWidth === 'function')
            ? null
            : (getComputedStyle(el).fontFamily || 'sans-serif');

        const measureWidth = (text, fs, nowrap) => {
            if (window.LLFlashcards && LLFlashcards.Util && typeof LLFlashcards.Util.measureTextWidth === 'function') {
                return LLFlashcards.Util.measureTextWidth(text, fs + 'px ' + (fontFamily || getComputedStyle(el).fontFamily || 'sans-serif'));
            }
            // Fallback: approximate using a hidden clone
            const clone = el.cloneNode(true);
            clone.style.visibility = 'hidden';
            clone.style.position = 'absolute';
            clone.style.fontSize = fs + 'px';
            clone.style.lineHeight = fs + 'px';
            clone.style.whiteSpace = nowrap ? 'nowrap' : 'normal';
            clone.style.maxWidth = nowrap ? 'none' : rect.width + 'px';
            clone.style.width = 'auto';
            card.appendChild(clone);
            const w = clone.scrollWidth;
            card.removeChild(clone);
            return w;
        };

        const text = el.textContent || '';
        const words = text.trim().split(/\s+/).filter(Boolean);
        const maxSize = 56;
        const minSize = 12;
        for (let fs = maxSize; fs >= minSize; fs--) {
            const longestWordWidth = words.length
                ? Math.max(...words.map(word => measureWidth(word, fs, true)))
                : measureWidth(text, fs, true);
            if (longestWordWidth > boxW) continue;
            el.style.fontSize = fs + 'px';
            el.style.lineHeight = fs + 'px';
            el.style.maxWidth = rect.width + 'px';
            el.style.whiteSpace = 'normal';
            el.style.wordBreak = 'normal';
            el.style.overflowWrap = 'break-word';
            if ((el.scrollHeight - paddingY) <= boxH) {
                break;
            }
        }
    }

    function setTypeForCurrentImage() {
        const el = window.llRecorder;
        if (!el.recordingTypeSelect) return;

        const img = images[currentImageIndex];
        const next = (img && Array.isArray(img.missing_types) && img.missing_types.length)
            ? img.missing_types[0]
            : el.recordingTypeSelect.value;

        if (!next) return;

        // Ensure the option exists; if not, create it so selection always shows
        let opt = Array.from(el.recordingTypeSelect.options).find(o => o.value === next);
        if (!opt) {
            opt = document.createElement('option');
            opt.value = next;
            const display = getRecordingTypeDisplay(next, window.ll_recorder_data?.recording_types || []);
            opt.textContent = display.text || display.label || next;
            el.recordingTypeSelect.appendChild(opt);
        }

        if (el.recordingTypeSelect.value !== next) {
            el.recordingTypeSelect.value = next;
            // Some themes/polyfills need a change event to redraw the visible part
            el.recordingTypeSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
        updateNewWordRecordingTypeLabel();
    }

    function showStatus(message, type) {
        const el = window.llRecorder;
        if (!el) return;
        if (isNewWordPanelActive()) {
            if (type === 'error') {
                flashNewWordAutoStatus('error', message);
            }
            return;
        }
        if (!el.status) return;
        el.status.textContent = message;
        el.status.className = 'll-upload-status';
        if (type) el.status.classList.add(type);
    }

    function handleSuccessfulUpload(recordingType, remaining, autoProcessed) {
        const el = window.llRecorder;
        if (!Array.isArray(images[currentImageIndex].existing_types)) {
            images[currentImageIndex].existing_types = [];
        }
        if (recordingType && !images[currentImageIndex].existing_types.includes(recordingType)) {
            images[currentImageIndex].existing_types.push(recordingType);
            images[currentImageIndex].existing_types = sortRecordingTypes(images[currentImageIndex].existing_types);
        }
        images[currentImageIndex].missing_types = sortRecordingTypes(remaining.slice());

        if (requireAll && remaining.length > 0) {
            if (isNewWordPanelActive()) {
                newWordUsingPanel = false;
                if (el.newWordPanel) el.newWordPanel.style.display = 'none';
                if (el.mainScreen) el.mainScreen.style.display = 'flex';
                syncProcessingReviewSlot();
            }
            setTypeForCurrentImage();
            resetRecordingState();
            refreshCurrentImageDisplay();
            scrollToTop();
            showStatus(i18n.saved_next_type || 'Saved. Next type selected.', 'success');
            return true; // Signal that we're staying on this image
        }

        const successMessage = autoProcessed
            ? (i18n.success_processed || 'Success! Recording published.')
            : (i18n.success || 'Success! Recording will be processed later.');
        resetRecordingState();
        showStatus(successMessage, 'success');
        setTimeout(() => loadImage(currentImageIndex + 1), 800);
        return false; // Signal that we're moving to next image
    }

    function setupEventListeners() {
        const el = window.llRecorder;
        if (el.recordBtn) el.recordBtn.addEventListener('click', toggleRecording);
        if (el.redoBtn) el.redoBtn.addEventListener('click', redo);
        if (el.submitBtn) el.submitBtn.addEventListener('click', submitAndNext);
        if (el.skipBtn) el.skipBtn.addEventListener('click', skipToNext);
        if (el.hideBtn) el.hideBtn.addEventListener('click', hideCurrentItem);
        if (el.reviewRedoBtn) {
            el.reviewRedoBtn.addEventListener('click', redo);
        }
        if (el.reviewSubmitBtn) {
            el.reviewSubmitBtn.addEventListener('click', submitAndNext);
        }
        if (el.hiddenToggleBtn) {
            el.hiddenToggleBtn.addEventListener('click', () => {
                setHiddenWordsPanelOpen(!hiddenWordsPanelOpen);
            });
        }
        if (el.hiddenCloseBtn) {
            el.hiddenCloseBtn.addEventListener('click', () => {
                setHiddenWordsPanelOpen(false);
            });
        }
        if (el.hiddenList) {
            el.hiddenList.addEventListener('click', handleHiddenListClick);
        }
        if (el.recordingTypeSelect) {
            el.recordingTypeSelect.addEventListener('change', updateNewWordRecordingTypeLabel);
        }
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && hiddenWordsPanelOpen) {
                setHiddenWordsPanelOpen(false);
            }
        });
    }

    // Proactively surface likely microphone issues so users know what to fix
    document.addEventListener('DOMContentLoaded', () => {
        preflightMicCheck().catch(() => { /* ignore */ });
    });

    async function preflightMicCheck() {
        // Only run if the UI exists
        if (!window.llRecorder || !window.llRecorder.status) return;

        // Insecure context cannot access mic; advise immediately
        if (!window.isSecureContext) {
            showStatus((i18n.insecure_context || 'Microphone requires a secure connection. Please use HTTPS or localhost.'), 'error');
            return;
        }

        // If Permissions API is available, warn if previously denied
        try {
            if (navigator.permissions && navigator.permissions.query) {
                const p = await navigator.permissions.query({ name: 'microphone' });
                if (p && p.state === 'denied') {
                    showStatus(
                        (i18n.mic_permission_blocked || 'Microphone permission is blocked for this site. Click the lock icon in the address bar → Site settings → allow Microphone, then reload.'),
                        'error'
                    );
                    return;
                }
            }
        } catch (_) { /* ignore */ }

        // If no audio inputs are detected, surface that
        try {
            if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
                const devices = await navigator.mediaDevices.enumerateDevices();
                const inputs = devices.filter(d => d.kind === 'audioinput');
                if (inputs.length === 0) {
                    showStatus((i18n.no_mic_devices || 'No microphone input devices detected. Check Windows microphone privacy and Sound settings.'), 'error');
                }
            }
        } catch (_) { /* ignore */ }
    }

    function getDisplayTitleForImage(img) {
        let displayTitle = decodeEntities(img?.title || '');
        const targetLang = window.ll_recorder_data?.language || 'TR';
        if (img?.use_word_display && img.word_title) {
            displayTitle = decodeEntities(img.word_title);
        } else if (img?.use_word_display && img.word_translation && img.word_translation.toLowerCase().includes(targetLang.toLowerCase())) {
            displayTitle = decodeEntities(img.word_translation);
        }
        return displayTitle;
    }

    function refreshCurrentImageDisplay() {
        const el = window.llRecorder;
        const img = images[currentImageIndex];
        if (!el || !img || !el.title) return;

        const displayTitle = getDisplayTitleForImage(img);
        el.title.textContent = displayTitle;
        if (img.is_text_only && el.textDisplay) {
            el.textDisplay.textContent = displayTitle;
            requestAnimationFrame(() => fitTextToContainer(el.textDisplay));
        }
    }

    function loadImage(index) {
        if (index >= images.length) {
            showComplete();
            return;
        }

        currentImageIndex = index;
        if (suppressNextScroll) {
            suppressNextScroll = false;
        } else {
            scrollToTop();
        }
        const img = images[index];
        const el = window.llRecorder;
        syncProcessingReviewSlot();

        el.currentNum.textContent = index + 1;
        el.totalNum.textContent = images.length;

        if (img.is_text_only) {
            el.image.style.display = 'none';
            if (!el.textDisplay) {
                el.textDisplay = document.createElement('div');
                el.textDisplay.className = 'quiz-text ll-text-display';
                const card = el.imageContainer.querySelector('.flashcard-container');
                card.classList.add('text-based');
                card.appendChild(el.textDisplay);
            }
            el.textDisplay.style.display = 'block';
            el.imageContainer.querySelector('.flashcard-container').classList.add('text-based');
        } else {
            if (el.textDisplay) {
                el.textDisplay.style.display = 'none';
            }
            el.image.style.display = 'block';
            el.image.src = img.image_url;
            const card = el.imageContainer.querySelector('.flashcard-container');
            card.classList.remove('text-based');
        }

        const displayTitle = getDisplayTitleForImage(img);

        el.title.textContent = displayTitle;
        if (img.is_text_only && el.textDisplay) {
            el.textDisplay.textContent = displayTitle;
        }
        if (!img.is_text_only) {
            // Remove text styling so image cards match quiz styling
            const card = el.imageContainer.querySelector('.flashcard-container');
            card.classList.remove('text-based');
            if (el.textDisplay) {
                el.textDisplay.textContent = '';
            }
        }
        const hideName = window.ll_recorder_data?.hide_name || false;
        if (hideName) {
            el.title.style.display = 'none';
        } else {
            el.title.style.display = '';
        }

        let categoryEl = document.getElementById('ll-image-category');
        if (!categoryEl) {
            categoryEl = document.createElement('p');
            categoryEl.id = 'll-image-category';
            categoryEl.className = 'll-image-category';
            el.title.parentNode.insertBefore(categoryEl, el.title.nextSibling);
        }
        categoryEl.textContent = (i18n.category || 'Category:') + ' ' +
            (img.category_name || i18n.uncategorized || 'Uncategorized');

        if (el.hideBtn) {
            const hasHideKey = !!getPrimaryHideKeyForItem(img);
            el.hideBtn.style.display = hasHideKey ? 'inline-flex' : 'none';
            el.hideBtn.disabled = !hasHideKey;
            el.hideBtn.innerHTML = icons.hide;
        }

        setTypeForCurrentImage();
        resetRecordingState();
        if (img.is_text_only && el.textDisplay) {
            requestAnimationFrame(() => fitTextToContainer(el.textDisplay));
        }
    }

    function resetRecordingState() {
        const el = window.llRecorder;
        if (el.recordBtn) {
            el.recordBtn.style.display = 'inline-flex';
            el.recordBtn.innerHTML = icons.record;
            el.recordBtn.classList.remove('recording');
            el.recordBtn.disabled = false;
        }
        if (el.skipBtn) el.skipBtn.disabled = false;
        if (el.hideBtn) {
            const currentItem = images[currentImageIndex] || null;
            const hasHideKey = !newWordMode && !!getPrimaryHideKeyForItem(currentItem);
            el.hideBtn.style.display = hasHideKey ? 'inline-flex' : 'none';
            el.hideBtn.disabled = !hasHideKey;
            el.hideBtn.innerHTML = icons.hide;
        }
        if (el.redoBtn) el.redoBtn.disabled = false;
        if (el.submitBtn) el.submitBtn.disabled = false;
        if (el.indicator) el.indicator.style.display = 'none';
        if (el.playbackControls) el.playbackControls.style.display = 'none';
        if (el.status) {
            el.status.textContent = '';
            el.status.className = 'll-upload-status';
        }
        if (el.newWordRecordBtn) {
            el.newWordRecordBtn.style.display = 'inline-flex';
            el.newWordRecordBtn.innerHTML = icons.record;
            el.newWordRecordBtn.classList.remove('recording');
            el.newWordRecordBtn.disabled = false;
        }
        if (el.newWordRecordingIndicator) el.newWordRecordingIndicator.style.display = 'none';
        if (el.newWordPlaybackControls) el.newWordPlaybackControls.style.display = 'none';
        if (el.newWordPlaybackAudio) el.newWordPlaybackAudio.removeAttribute('src');
        if (el.newWordStartBtn && isNewWordPanelActive()) el.newWordStartBtn.disabled = true;
        if (el.processingReview) {
            el.processingReview.style.display = 'none';
        }
        if (el.reviewContainer) {
            el.reviewContainer.innerHTML = '';
        }
        if (el.reviewSubmitBtn) {
            el.reviewSubmitBtn.disabled = false;
        }
        if (el.reviewRedoBtn) {
            el.reviewRedoBtn.disabled = false;
        }
        if (newWordMode) {
            if (newWordUsingPanel) {
                if (el.newWordPanel) el.newWordPanel.style.display = 'block';
                if (el.mainScreen) el.mainScreen.style.display = 'none';
            } else if (el.mainScreen) {
                el.mainScreen.style.display = 'flex';
                if (el.newWordPanel) el.newWordPanel.style.display = 'none';
            }
        } else if (el.mainScreen) {
            el.mainScreen.style.display = 'flex';
            if (el.newWordPanel) el.newWordPanel.style.display = 'none';
        }
        currentBlob = null;
        audioChunks = [];
        processingState = null;
        activeRecordingControls = null;
    }

    async function toggleRecording() {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            stopRecording();
        } else {
            await startRecording();
        }
    }

    async function startRecording() {
        const controls = getActiveControls();
        if (!controls || !controls.recordBtn) return;

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });

            // Prioritize formats by audio processing quality
            // Avoid Opus (generic webm) - causes issues with noise reduction/processing
            const options = {};

            // Best: uncompressed WAV (lossless, ideal for processing)
            if (MediaRecorder.isTypeSupported('audio/wav')) {
                options.mimeType = 'audio/wav';
            }
            // Second best: WebM with PCM (lossless)
            else if (MediaRecorder.isTypeSupported('audio/webm;codecs=pcm')) {
                options.mimeType = 'audio/webm;codecs=pcm';
            }
            // Good: MP3 (lossy but mature codec, excellent tool support)
            else if (MediaRecorder.isTypeSupported('audio/mpeg') || MediaRecorder.isTypeSupported('audio/mp3')) {
                options.mimeType = MediaRecorder.isTypeSupported('audio/mpeg') ? 'audio/mpeg' : 'audio/mp3';
            }
            // iOS fallback: MP4 (usually AAC - lossy but good processing support)
            else if (MediaRecorder.isTypeSupported('audio/mp4')) {
                options.mimeType = 'audio/mp4';
            }
            // iOS alternate: AAC (good processing support)
            else if (MediaRecorder.isTypeSupported('audio/aac')) {
                options.mimeType = 'audio/aac';
            }
            // Last resort: allow Opus/webm so we record something instead of failing silently
            else if (MediaRecorder.isTypeSupported('audio/webm')) {
                options.mimeType = 'audio/webm';
                console.warn('Falling back to audio/webm (Opus); processing quality may be lower.');
            }
            else {
                console.error('No suitable audio format supported by this browser');
                throw new Error('Browser does not support required audio formats for recording');
            }

            mediaRecorder = new MediaRecorder(stream, options);

            audioChunks = [];
            mediaRecorder.ondataavailable = (e) => audioChunks.push(e.data);
            mediaRecorder.onstop = handleRecordingStopped;

            mediaRecorder.start();
            recordingStartTime = Date.now();

            activeRecordingControls = controls;
            controls.recordBtn.innerHTML = icons.stop;
            controls.recordBtn.classList.add('recording');
            if (controls.indicator) {
                controls.indicator.style.display = 'block';
            }
            if (controls.skipBtn) {
                controls.skipBtn.disabled = true;
            }
            if (controls.hideBtn) {
                controls.hideBtn.disabled = true;
            }

            timerInterval = setInterval(updateTimer, 100);

        } catch (err) {
            console.error('Error accessing microphone:', err);
            console.error('Error name:', err?.name);
            console.error('Error message:', err?.message);

            const message = await buildMicErrorMessage(err);
            showStatus(message, 'error');
            activeRecordingControls = null;
            // Make sure UI remains usable after failure
            if (controls.recordBtn) controls.recordBtn.disabled = false;
            if (controls.skipBtn) controls.skipBtn.disabled = false;
            if (controls.hideBtn) controls.hideBtn.disabled = false;
        }
    }

    async function buildMicErrorMessage(err) {
        const name = err && err.name ? String(err.name) : '';
        const isSecure = !!window.isSecureContext;

        let permState = 'unknown';
        try {
            if (navigator.permissions && navigator.permissions.query) {
                const p = await navigator.permissions.query({ name: 'microphone' });
                permState = p?.state || 'unknown';
            }
        } catch (_) { /* ignore */ }

        let inputCount = -1;
        try {
            if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
                const devices = await navigator.mediaDevices.enumerateDevices();
                inputCount = devices.filter(d => d.kind === 'audioinput').length;
            }
        } catch (_) { /* ignore */ }

        // Default fallback
        let msg = i18n.microphone_error || 'Error: Could not access microphone';

        // Insecure context
        if (!isSecure) {
            return 'Microphone requires a secure connection. Open this page over HTTPS or localhost and try again.';
        }

        // Tailored messages by error name
        switch (name) {
            case 'NotAllowedError':
            case 'PermissionDeniedError':
                if (permState === 'denied') {
                    msg = 'Microphone permission is blocked for this site. Click the lock icon → Site settings → set Microphone to Allow, then reload the page.';
                } else {
                    msg = 'Microphone access was not granted. If no browser prompt appears, open Site settings from the lock icon and allow Microphone for this site, then reload.';
                }
                break;
            case 'NotReadableError':
                msg = 'Microphone is in use by another app or blocked by the OS. Close apps that use the mic (Zoom/Teams/Discord), then try again.';
                break;
            case 'NotFoundError':
            case 'DevicesNotFoundError':
                msg = 'No microphone found. Connect a microphone and check Windows Privacy & Sound settings.';
                break;
            case 'OverconstrainedError':
            case 'ConstraintNotSatisfiedError':
                msg = 'The selected microphone doesn’t meet the requested constraints. Set your default input device in system settings and try again.';
                break;
            case 'SecurityError':
                msg = 'Browser blocked access due to security policy. Ensure you are on HTTPS and try again.';
                break;
            case 'AbortError':
                msg = 'Audio capture aborted unexpectedly. Try again.';
                break;
            default:
                // Keep default
                break;
        }

        // Augment with quick Windows/Chrome hints
        const hints = [];
        if (permState === 'denied') {
            hints.push('Windows/Chrome: Click the lock icon → Site settings → Microphone: Allow.');
        }
        if (inputCount === 0) {
            hints.push('No input devices detected: Windows Settings → Privacy & Security → Microphone → allow apps and desktop apps; then check Sound settings for the default input.');
        }

        if (hints.length) {
            msg += ' ' + hints.join(' ');
        }

        return msg;
    }

    function stopRecording() {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
            mediaRecorder.stream.getTracks().forEach(track => track.stop());
            clearInterval(timerInterval);
        }
    }

    function updateTimer() {
        const elapsed = (Date.now() - recordingStartTime) / 1000;
        const minutes = Math.floor(elapsed / 60);
        const seconds = Math.floor(elapsed % 60);
        const controls = activeRecordingControls || getActiveControls();
        if (controls && controls.timer) {
            controls.timer.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
        }
    }

    async function handleRecordingStopped() {
        const el = window.llRecorder;
        const controls = activeRecordingControls || getActiveControls();

        const mimeType = mediaRecorder.mimeType || 'audio/webm';
        currentBlob = new Blob(audioChunks, { type: mimeType });

        if (controls.recordBtn) {
            controls.recordBtn.style.display = 'none';
            controls.recordBtn.disabled = true;
        }
        if (controls.indicator) {
            controls.indicator.style.display = 'none';
        }
        if (controls.skipBtn) {
            controls.skipBtn.disabled = false;
        }
        if (controls.hideBtn) {
            controls.hideBtn.disabled = false;
        }
        if (isNewWordPanelActive() && el.newWordStartBtn) {
            el.newWordStartBtn.disabled = false;
        }

        if (isNewWordPanelActive()) {
            maybeTranscribeNewWordRecording(currentBlob);
        }

        if (!autoProcessEnabled) {
            showRawPlayback(currentBlob, controls);
            return;
        }

        showStatus(i18n.processing || 'Processing audio...', 'info');
        if (el.reviewSubmitBtn) el.reviewSubmitBtn.disabled = true;
        if (el.reviewRedoBtn) el.reviewRedoBtn.disabled = true;

        try {
            const processed = await processRecordedBlob(currentBlob, { ...processingDefaults });
            processingState = {
                originalBuffer: processed.originalBuffer,
                processedBuffer: processed.processedBuffer,
                trimStart: processed.trimStart,
                trimEnd: processed.trimEnd,
                options: { ...processingDefaults },
                manualBoundaries: false,
                reviewReady: false
            };
            showProcessingReview();
            showStatus(i18n.processing_ready || 'Review the processed audio below.', 'success');
        } catch (error) {
            console.error('Audio processing failed:', error);
            processingState = null;
            showStatus(i18n.processing_failed || 'Audio processing failed. You can upload the raw recording instead.', 'error');
            showRawPlayback(currentBlob);
        } finally {
            if (el.reviewSubmitBtn) el.reviewSubmitBtn.disabled = false;
            if (el.reviewRedoBtn) el.reviewRedoBtn.disabled = false;
        }
    }

    function showRawPlayback(blob, controlsOverride) {
        const el = window.llRecorder;
        const controls = controlsOverride || getActiveControls();
        if (el.processingReview) {
            el.processingReview.style.display = 'none';
        }
        if (el.reviewContainer) {
            el.reviewContainer.innerHTML = '';
        }
        if (!controls.isNewWordPanel && el.mainScreen) {
            el.mainScreen.style.display = 'flex';
        }
        if (!controls.playbackControls || !controls.playbackAudio) {
            return;
        }

        const url = URL.createObjectURL(blob);
        controls.playbackAudio.src = url;
        controls.playbackControls.style.display = 'block';

        if (controls.redoBtn) {
            controls.redoBtn.innerHTML = icons.redo;
        }
        if (!controls.isNewWordPanel && el.submitBtn) {
            el.submitBtn.innerHTML = icons.check;
        }

        controls.playbackAudio.play().catch(() => {});
    }

    function showProcessingReview() {
        const el = window.llRecorder;
        if (!el.processingReview || !el.reviewContainer || !processingState) {
            showRawPlayback(currentBlob);
            return;
        }

        syncProcessingReviewSlot();
        el.reviewContainer.innerHTML = '';
        const reviewFile = createReviewFileElement(processingState);
        el.reviewContainer.appendChild(reviewFile);
        el.processingReview.style.display = 'block';
        processingState.reviewReady = true;
        if (!isNewWordPanelActive() && el.mainScreen) {
            el.mainScreen.style.display = 'none';
        }
        if (el.playbackControls) {
            el.playbackControls.style.display = 'none';
        }

        requestAnimationFrame(() => {
            renderWaveform(reviewFile, processingState.originalBuffer, processingState.trimStart, processingState.trimEnd);
            setupAudioPlayback(reviewFile, processingState.processedBuffer);
            setupBoundaryDragging(reviewFile, processingState.originalBuffer);
            const audio = reviewFile.querySelector('audio');
            if (audio) {
                audio.play().catch(() => {});
            }
        });
    }

    async function processRecordedBlob(blob, options) {
        if (!processingAudioContext) {
            processingAudioContext = new (window.AudioContext || window.webkitAudioContext)();
        }
        if (processingAudioContext.state === 'suspended') {
            await processingAudioContext.resume();
        }

        const arrayBuffer = await blob.arrayBuffer();
        const originalBuffer = await processingAudioContext.decodeAudioData(arrayBuffer.slice(0));

        let processedBuffer = originalBuffer;
        let trimStart = 0;
        let trimEnd = originalBuffer.length;

        if (options.enableTrim) {
            const trimResult = detectSilenceBoundaries(originalBuffer);
            trimStart = trimResult.start;
            trimEnd = trimResult.end;
            processedBuffer = trimSilence(originalBuffer, trimStart, trimEnd);
        }

        if (options.enableNoise) {
            processedBuffer = await reduceNoise(processedBuffer);
        }

        if (options.enableLoudness) {
            processedBuffer = await normalizeLoudness(processedBuffer);
        }

        return {
            originalBuffer,
            processedBuffer,
            trimStart,
            trimEnd
        };
    }

    function redo() {
        if (newWordMode) {
            newWordTranscriptionDone = false;
            newWordTranscriptionInFlight = false;
            newWordTranslationInFlight = false;
            newWordAutoCancelled = false;
            clearNewWordTextFields();
            resetNewWordAutoState();
        }
        resetRecordingState();
    }

    async function skipToNext() {
        if (!requireAll) {
            loadImage(currentImageIndex + 1);
            return;
        }
        const el = window.llRecorder;
        if (!el.recordingTypeSelect) return;

        const img = images[currentImageIndex];
        if (!img || !Array.isArray(img.missing_types) || img.missing_types.length === 0) {
            loadImage(currentImageIndex + 1);
            return;
        }

        const curType = el.recordingTypeSelect.value;
        if (!curType) return;

        // Remove the current type for this session only
        images[currentImageIndex].missing_types = sortRecordingTypes(img.missing_types.filter(t => t !== curType));

        if (images[currentImageIndex].missing_types.length > 0) {
            setTypeForCurrentImage();
            resetRecordingState();
            showStatus(i18n.skipped_type || 'Skipped this type. Next type selected.', 'info');
        } else {
            loadImage(currentImageIndex + 1);
        }
    }

    async function handleHiddenListClick(event) {
        const trigger = event.target && event.target.closest
            ? event.target.closest('.ll-hidden-word-unhide')
            : null;
        if (!trigger) return;

        const hideKey = sanitizeHideKey(trigger.dataset.hideKey || '');
        if (!hideKey) return;

        trigger.disabled = true;
        try {
            await unhideItemByKey(hideKey);
        } finally {
            trigger.disabled = false;
        }
    }

    async function unhideItemByKey(hideKey) {
        if (!hideKey || !ajaxUrl || !nonce) return;

        const formData = new FormData();
        formData.append('action', 'll_unhide_recording_word');
        formData.append('nonce', nonce);
        formData.append('hide_key', hideKey);

        try {
            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            const data = await response.json();
            if (!data?.success) {
                const message = (typeof data?.data === 'string' ? data.data : data?.data?.message) || 'Request failed';
                throw new Error(message);
            }

            setHiddenWordsFromServer(data.data);
            showStatus(i18n.unhide_success || 'Word unhidden.', 'success');

            if (images.length === 0 && !newWordMode) {
                window.location.reload();
            }
        } catch (err) {
            const detail = err?.message || '';
            showStatus(`${i18n.unhide_failed || 'Unhide failed:'} ${detail}`.trim(), 'error');
        }
    }

    async function hideCurrentItem() {
        if (newWordMode) return;
        if (!ajaxUrl || !nonce) return;
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            showStatus(i18n.hide_while_recording || 'Stop recording before hiding this word.', 'error');
            return;
        }

        const el = window.llRecorder;
        const img = images[currentImageIndex];
        if (!img) return;

        const hideKey = getPrimaryHideKeyForItem(img);
        if (!hideKey) {
            showStatus(i18n.hide_failed || 'Hide failed:', 'error');
            return;
        }

        showStatus(i18n.hiding || 'Hiding...', 'info');
        if (el?.hideBtn) el.hideBtn.disabled = true;

        const formData = new FormData();
        formData.append('action', 'll_hide_recording_word');
        formData.append('nonce', nonce);
        formData.append('hide_key', hideKey);
        formData.append('image_id', img.id || 0);
        formData.append('word_id', img.word_id || 0);
        formData.append('title', img.word_title || img.title || '');
        formData.append('category_name', img.category_name || '');
        formData.append('category_slug', img.category_slug || '');

        try {
            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            const data = await response.json();
            if (!data?.success) {
                const message = (typeof data?.data === 'string' ? data.data : data?.data?.message) || 'Request failed';
                throw new Error(message);
            }

            setHiddenWordsFromServer(data.data);

            const hiddenKeySet = new Set(getItemHideKeys(img));
            const serverKey = sanitizeHideKey(data?.data?.entry?.key || hideKey);
            if (serverKey) hiddenKeySet.add(serverKey);
            for (let idx = images.length - 1; idx >= 0; idx -= 1) {
                if (itemMatchesHideKeySet(images[idx], hiddenKeySet)) {
                    images.splice(idx, 1);
                }
            }

            if (images.length === 0) {
                showStatus(i18n.hidden_success || 'Word hidden. Moving to the next item.', 'success');
                showComplete();
                return;
            }

            if (currentImageIndex >= images.length) {
                currentImageIndex = images.length - 1;
            }
            loadImage(currentImageIndex);
            showStatus(i18n.hidden_success || 'Word hidden. Moving to the next item.', 'success');
        } catch (err) {
            const detail = err?.message || '';
            showStatus(`${i18n.hide_failed || 'Hide failed:'} ${detail}`.trim(), 'error');
            if (el?.hideBtn) el.hideBtn.disabled = false;
        }
    }

    function restoreUploadControls() {
        const el = window.llRecorder;
        if (!el) return;
        if (el.submitBtn) el.submitBtn.disabled = false;
        if (el.redoBtn) el.redoBtn.disabled = false;
        if (el.reviewSubmitBtn) el.reviewSubmitBtn.disabled = false;
        if (el.reviewRedoBtn) el.reviewRedoBtn.disabled = false;
        if (el.skipBtn) el.skipBtn.disabled = false;
        if (el.hideBtn) el.hideBtn.disabled = false;
        if (isNewWordPanelActive()) {
            setNewWordActionState(false);
        }
    }

    function getAjaxErrorMessage(payload, fallback = '') {
        if (!payload || typeof payload !== 'object') return fallback;
        if (typeof payload.data === 'string' && payload.data.trim()) {
            return payload.data.trim();
        }
        if (payload.data && typeof payload.data === 'object' && typeof payload.data.message === 'string' && payload.data.message.trim()) {
            return payload.data.message.trim();
        }
        if (typeof payload.message === 'string' && payload.message.trim()) {
            return payload.message.trim();
        }
        return fallback;
    }

    function showUploadError(detail) {
        const prefix = i18n.upload_failed || 'Upload failed:';
        if (!detail) {
            showStatus(prefix, 'error');
            return;
        }
        showStatus(`${prefix} ${detail}`.trim(), 'error');
    }

    async function submitAndNext() {
        if (!currentBlob) {
            const msg = newWordMode
                ? (i18n.new_word_missing_recording || 'Record audio before saving this word.')
                : (i18n.no_blob || 'No audio captured. Please record before saving.');
            console.error(msg);
            showStatus(msg, 'error');
            return;
        }

        const el = window.llRecorder;
        const img = images[currentImageIndex];
        if (!img) {
            showStatus(i18n.upload_failed || 'Upload failed:', 'error');
            return;
        }
        if (newWordMode) {
            if (!newWordPrepared) {
                flashNewWordAutoStatus('error', i18n.new_word_failed || 'New word setup failed:');
                return;
            }
            if (newWordTranscriptionInFlight || newWordTranslationInFlight) {
                updateNewWordAutoUI();
                return;
            }
            const updated = await maybeUpdateNewWordText(true);
            if (!updated) {
                return;
            }
        }
        const recordingType = el.recordingTypeSelect?.value || 'isolation';
        if (autoProcessEnabled && processingState && !processingState.reviewReady) {
            if (el.processingReview && el.reviewContainer) {
                showProcessingReview();
                showStatus(i18n.processing_ready || 'Review the processed audio below.', 'info');
                return;
            }
        }
        const autoProcessed = autoProcessEnabled && !!processingState?.processedBuffer && !!processingState?.reviewReady;
        const uploadBlob = autoProcessed ? audioBufferToWav(processingState.processedBuffer) : currentBlob;

        const wordsetIds = Array.isArray(window.ll_recorder_data?.wordset_ids) ? window.ll_recorder_data.wordset_ids : [];
        const wordsetLegacy = window.ll_recorder_data?.wordset || '';
        const includeTypes = window.ll_recorder_data?.include_types || '';
        const excludeTypes = window.ll_recorder_data?.exclude_types || '';
        const activeCategory = isNewWordPanelActive()
            ? (img.category_slug || el.newWordCategory?.value || '')
            : (el.categorySelect?.value || '');

        showStatus(i18n.uploading || 'Uploading...', 'uploading');
        if (el.submitBtn) el.submitBtn.disabled = true;
        if (el.redoBtn) el.redoBtn.disabled = true;
        if (el.skipBtn) el.skipBtn.disabled = true;
        if (el.hideBtn) el.hideBtn.disabled = true;
        if (isNewWordPanelActive()) {
            setNewWordActionState(true);
        }
        if (el.reviewSubmitBtn) el.reviewSubmitBtn.disabled = true;
        if (el.reviewRedoBtn) el.reviewRedoBtn.disabled = true;

        const formData = new FormData();
        formData.append('action', 'll_upload_recording');
        formData.append('nonce', nonce);
        formData.append('image_id', img.id || 0);
        formData.append('word_id', img.word_id || 0);
        formData.append('recording_type', recordingType);
        formData.append('wordset_ids', JSON.stringify(wordsetIds));
        formData.append('wordset', wordsetLegacy);
        formData.append('include_types', includeTypes);
        formData.append('exclude_types', excludeTypes);
        formData.append('word_title', img.word_title || img.title || '');
        formData.append('active_category', activeCategory);

        // Extension detection - prioritize quality formats
        const extension = getBlobExtension(uploadBlob.type);

        formData.append('audio', uploadBlob, `${img.title}${extension}`);
        if (autoProcessed) {
            formData.append('auto_processed', '1');
        }

        try {
            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });

            let data = null;
            const contentType = response.headers.get('content-type') || '';
            if (contentType.includes('application/json')) {
                try {
                    data = await response.json();
                } catch (parseError) {
                    data = null;
                }
            }

            if (response.ok && data?.success) {
                if (data.data?.word_id && !img.word_id) {
                    img.word_id = data.data.word_id;
                }
                const remaining = Array.isArray(data.data?.remaining_types) ? data.data.remaining_types : [];
                handleSuccessfulUpload(recordingType, remaining, autoProcessed);
                return;
            }

            const uploadErrorMessage = getAjaxErrorMessage(data);
            if (data && uploadErrorMessage !== '' && response.status < 500) {
                showUploadError(uploadErrorMessage);
                restoreUploadControls();
                return;
            }

            await verifyAfterError({
                img,
                recordingType,
                wordsetIds,
                wordsetLegacy,
                includeTypes,
                excludeTypes,
                autoProcessed,
                uploadErrorMessage
            });
        } catch (err) {
            await verifyAfterError({
                img,
                recordingType,
                wordsetIds,
                wordsetLegacy,
                includeTypes,
                excludeTypes,
                autoProcessed,
                uploadErrorMessage: err?.message || ''
            });
        }
    }

    async function verifyAfterError({ img, recordingType, wordsetIds, wordsetLegacy, includeTypes, excludeTypes, autoProcessed, uploadErrorMessage = '' }) {
        const el = window.llRecorder;
        const activeCategory = isNewWordPanelActive()
            ? (img?.category_slug || el?.newWordCategory?.value || '')
            : (el?.categorySelect?.value || '');
        try {
            const fd = new FormData();
            fd.append('action', 'll_verify_recording');
            fd.append('nonce', nonce);
            fd.append('image_id', img.id || 0);
            fd.append('word_id', img.word_id || 0);
            fd.append('recording_type', recordingType);
            fd.append('wordset_ids', JSON.stringify(wordsetIds));
            fd.append('wordset', wordsetLegacy);
            fd.append('include_types', includeTypes);
            fd.append('exclude_types', excludeTypes);
            fd.append('word_title', img.word_title || img.title || '');
            fd.append('active_category', activeCategory);

            const verifyResp = await fetch(ajaxUrl, { method: 'POST', body: fd });
            if (!verifyResp.ok) throw new Error(`HTTP ${verifyResp.status}: ${verifyResp.statusText}`);
            const verifyData = await verifyResp.json();

            if (verifyData?.success && verifyData.data?.found_audio_post_id) {
                const remaining = Array.isArray(verifyData.data.remaining_types) ? verifyData.data.remaining_types : [];
                handleSuccessfulUpload(recordingType, remaining, autoProcessed);
                return;
            }

            showUploadError(uploadErrorMessage || 'HTTP 500 (no recording found)');
        } catch (e) {
            console.error('Verify error:', e);
            showUploadError(uploadErrorMessage || e.message || 'Verify failed');
        } finally {
            restoreUploadControls();
        }
    }

    // Auto-processing review helpers
    function createReviewFileElement(data) {
        const div = document.createElement('div');
        div.className = 'll-review-file';

        const img = images[currentImageIndex] || {};
        const displayTitle = getDisplayTitleForImage(img) || window.llRecorder?.title?.textContent || img.title || '';
        const imageUrl = img.image_url || '';
        const categoryLabel = img.category_name || i18n.uncategorized || 'Uncategorized';
        const imageHtml = (!img.is_text_only && imageUrl)
            ? `<img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(displayTitle)}" class="ll-review-thumbnail">`
            : '';
        const categoryHtml = `<span class="ll-review-category"><strong>${escapeHtml(i18n.category || 'Category:')}</strong> ${escapeHtml(categoryLabel)}</span>`;
        div.innerHTML = `
            <div class="ll-review-header">
                <div class="ll-review-title-section">
                    ${imageHtml}
                    <div class="ll-review-title-info">
                        <h3 class="ll-review-title">${escapeHtml(displayTitle)}</h3>
                        <div class="ll-review-metadata">
                            ${categoryHtml}
                        </div>
                    </div>
                </div>
            </div>
            <div class="ll-waveform-container">
                <canvas class="ll-waveform-canvas"></canvas>
            </div>
            <div class="ll-playback-controls">
                <button type="button" class="ll-btn ll-btn-secondary ll-review-play" title="Play" aria-label="Play"></button>
                <audio controls preload="auto"></audio>
            </div>
        `;

        const playButton = div.querySelector('.ll-review-play');
        if (playButton) {
            playButton.innerHTML = icons.play;
            playButton.addEventListener('click', () => {
                const audio = div.querySelector('audio');
                if (!audio) return;
                audio.currentTime = 0;
                audio.play().catch(() => {});
            });
        }

        return div;
    }

    function wireReviewEvents(container) {
        const trim = container.querySelector('.ll-file-trim');
        const noise = container.querySelector('.ll-file-noise');
        const loudness = container.querySelector('.ll-file-loudness');

        [trim, noise, loudness].forEach(control => {
            if (control) {
                control.addEventListener('change', () => updateProcessedAudio(container));
            }
        });

        const reprocessBtn = container.querySelector('.ll-reprocess-btn');
        if (reprocessBtn) {
            reprocessBtn.addEventListener('click', () => reprocessCurrentRecording(container));
        }
    }

    function getProcessingContext() {
        if (!processingAudioContext) {
            processingAudioContext = new (window.AudioContext || window.webkitAudioContext)();
        }
        if (processingAudioContext.state === 'suspended') {
            processingAudioContext.resume().catch(() => {});
        }
        return processingAudioContext;
    }

    function renderWaveform(container, audioBuffer, trimStart, trimEnd) {
        const canvas = container.querySelector('.ll-waveform-canvas');
        const waveformContainer = container.querySelector('.ll-waveform-container');
        if (!canvas || !waveformContainer) return;

        const rect = waveformContainer.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) {
            return;
        }

        const dpr = window.devicePixelRatio || 1;
        canvas.width = rect.width * dpr;
        canvas.height = rect.height * dpr;
        canvas.style.width = rect.width + 'px';
        canvas.style.height = rect.height + 'px';

        const ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);

        const channelData = audioBuffer.getChannelData(0);
        const samplesPerPixel = Math.floor(channelData.length / rect.width);
        const centerY = rect.height / 2;

        ctx.fillStyle = '#2ecc71';
        ctx.strokeStyle = '#27ae60';
        ctx.lineWidth = 1;

        for (let x = 0; x < rect.width; x++) {
            const start = x * samplesPerPixel;
            const end = start + samplesPerPixel;
            let min = 1;
            let max = -1;

            for (let i = start; i < end && i < channelData.length; i++) {
                const sample = channelData[i];
                if (sample < min) min = sample;
                if (sample > max) max = sample;
            }

            const yTop = centerY - (max * centerY);
            const yBottom = centerY - (min * centerY);
            const height = yBottom - yTop;

            ctx.fillRect(x, yTop, 1, height);
        }

        addTrimBoundaries(waveformContainer, trimStart, trimEnd, audioBuffer.length);
    }

    function addTrimBoundaries(container, trimStart, trimEnd, totalSamples) {
        const startPercent = (trimStart / totalSamples) * 100;
        const endPercent = (trimEnd / totalSamples) * 100;

        container.querySelectorAll('.ll-trim-boundary, .ll-trimmed-region').forEach(el => el.remove());

        const startBoundary = document.createElement('div');
        startBoundary.className = 'll-trim-boundary ll-start';
        startBoundary.style.left = startPercent + '%';
        startBoundary.dataset.position = trimStart;
        container.appendChild(startBoundary);

        const endBoundary = document.createElement('div');
        endBoundary.className = 'll-trim-boundary ll-end';
        endBoundary.style.left = endPercent + '%';
        endBoundary.dataset.position = trimEnd;
        container.appendChild(endBoundary);

        if (trimStart > 0) {
            const startRegion = document.createElement('div');
            startRegion.className = 'll-trimmed-region ll-start';
            startRegion.style.width = startPercent + '%';
            container.appendChild(startRegion);
        }

        if (trimEnd < totalSamples) {
            const endRegion = document.createElement('div');
            endRegion.className = 'll-trimmed-region ll-end';
            endRegion.style.left = endPercent + '%';
            endRegion.style.width = (100 - endPercent) + '%';
            container.appendChild(endRegion);
        }
    }

    function setupAudioPlayback(container, audioBuffer) {
        const audio = container.querySelector('audio');
        if (!audio) return;

        const blob = audioBufferToWav(audioBuffer);
        if (audio.dataset.blobUrl) {
            URL.revokeObjectURL(audio.dataset.blobUrl);
        }
        const url = URL.createObjectURL(blob);
        audio.dataset.blobUrl = url;
        audio.src = url;
    }

    function setupBoundaryDragging(container, audioBuffer) {
        const waveformContainer = container.querySelector('.ll-waveform-container');
        if (!waveformContainer) return;

        const startBoundary = waveformContainer.querySelector('.ll-trim-boundary.ll-start');
        const endBoundary = waveformContainer.querySelector('.ll-trim-boundary.ll-end');
        if (!startBoundary || !endBoundary) return;

        let isDragging = false;
        let currentBoundary = null;
        let containerRect = null;

        const startDrag = (e, boundary) => {
            isDragging = true;
            currentBoundary = boundary;
            containerRect = waveformContainer.getBoundingClientRect();
            waveformContainer.style.cursor = 'ew-resize';
            e.preventDefault();
        };

        const onDrag = (e) => {
            if (!isDragging || !currentBoundary || !containerRect) return;

            const clientX = e.type.includes('touch') ? e.touches[0].clientX : e.clientX;
            const relativeX = clientX - containerRect.left;
            const percent = Math.max(0, Math.min(100, (relativeX / containerRect.width) * 100));

            const isStart = currentBoundary.classList.contains('ll-start');
            const otherBoundary = isStart ? endBoundary : startBoundary;
            const otherPercent = parseFloat(otherBoundary.style.left);

            let constrainedPercent = percent;
            if (isStart && percent >= otherPercent - 1) {
                constrainedPercent = otherPercent - 1;
            } else if (!isStart && percent <= otherPercent + 1) {
                constrainedPercent = otherPercent + 1;
            }

            currentBoundary.style.left = constrainedPercent + '%';
            const samplePosition = Math.floor((constrainedPercent / 100) * audioBuffer.length);
            currentBoundary.dataset.position = samplePosition;

            updateTrimmedRegions(waveformContainer, startBoundary, endBoundary);
            e.preventDefault();
        };

        const endDrag = () => {
            if (!isDragging) return;
            isDragging = false;
            waveformContainer.style.cursor = '';

            const newTrimStart = parseInt(startBoundary.dataset.position, 10);
            const newTrimEnd = parseInt(endBoundary.dataset.position, 10);

            if (processingState) {
                processingState.trimStart = newTrimStart;
                processingState.trimEnd = newTrimEnd;
                processingState.manualBoundaries = true;
                updateProcessedAudio(container);
            }

            currentBoundary = null;
            containerRect = null;
        };

        startBoundary.addEventListener('mousedown', (e) => startDrag(e, startBoundary));
        endBoundary.addEventListener('mousedown', (e) => startDrag(e, endBoundary));
        document.addEventListener('mousemove', onDrag);
        document.addEventListener('mouseup', endDrag);

        startBoundary.addEventListener('touchstart', (e) => startDrag(e, startBoundary));
        endBoundary.addEventListener('touchstart', (e) => startDrag(e, endBoundary));
        document.addEventListener('touchmove', onDrag, { passive: false });
        document.addEventListener('touchend', endDrag);

        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.removedNodes.forEach((node) => {
                    if (node === container) {
                        document.removeEventListener('mousemove', onDrag);
                        document.removeEventListener('mouseup', endDrag);
                        document.removeEventListener('touchmove', onDrag);
                        document.removeEventListener('touchend', endDrag);
                        observer.disconnect();
                    }
                });
            });
        });
        if (!container.parentNode) {
            return;
        }
        observer.observe(container.parentNode, { childList: true });
    }

    function updateTrimmedRegions(container, startBoundary, endBoundary) {
        const startPercent = parseFloat(startBoundary.style.left);
        const endPercent = parseFloat(endBoundary.style.left);

        container.querySelectorAll('.ll-trimmed-region').forEach(el => el.remove());

        if (startPercent > 0) {
            const startRegion = document.createElement('div');
            startRegion.className = 'll-trimmed-region ll-start';
            startRegion.style.width = startPercent + '%';
            container.appendChild(startRegion);
        }

        if (endPercent < 100) {
            const endRegion = document.createElement('div');
            endRegion.className = 'll-trimmed-region ll-end';
            endRegion.style.left = endPercent + '%';
            endRegion.style.width = (100 - endPercent) + '%';
            container.appendChild(endRegion);
        }
    }

    async function updateProcessedAudio(container) {
        if (!processingState) return;

        const audioElement = container.querySelector('audio');
        if (!audioElement) return;

        audioElement.style.opacity = '0.5';
        try {
            const enableTrimControl = container.querySelector('.ll-file-trim');
            const enableNoiseControl = container.querySelector('.ll-file-noise');
            const enableLoudnessControl = container.querySelector('.ll-file-loudness');
            const enableTrim = enableTrimControl ? enableTrimControl.checked : true;
            const enableNoise = enableNoiseControl ? enableNoiseControl.checked : true;
            const enableLoudness = enableLoudnessControl ? enableLoudnessControl.checked : true;

            let processedBuffer = processingState.originalBuffer;

            if (enableTrim) {
                processedBuffer = trimSilence(processingState.originalBuffer, processingState.trimStart, processingState.trimEnd);
            }

            if (enableNoise) {
                processedBuffer = await reduceNoise(processedBuffer);
            }

            if (enableLoudness) {
                processedBuffer = await normalizeLoudness(processedBuffer);
            }

            processingState.processedBuffer = processedBuffer;
            processingState.options = { enableTrim, enableNoise, enableLoudness };

            setupAudioPlayback(container, processedBuffer);
        } catch (error) {
            console.error('Error updating processed audio:', error);
        } finally {
            audioElement.style.opacity = '1';
        }
    }

    async function reprocessCurrentRecording(container) {
        if (!processingState) return;

        const enableTrimControl = container.querySelector('.ll-file-trim');
        const enableNoiseControl = container.querySelector('.ll-file-noise');
        const enableLoudnessControl = container.querySelector('.ll-file-loudness');
        const enableTrim = enableTrimControl ? enableTrimControl.checked : true;
        const enableNoise = enableNoiseControl ? enableNoiseControl.checked : true;
        const enableLoudness = enableLoudnessControl ? enableLoudnessControl.checked : true;

        const reprocessBtn = container.querySelector('.ll-reprocess-btn');
        const originalText = reprocessBtn ? reprocessBtn.textContent : '';
        if (reprocessBtn) {
            reprocessBtn.textContent = 'Processing...';
            reprocessBtn.disabled = true;
        }

        try {
            let trimStart = processingState.trimStart;
            let trimEnd = processingState.trimEnd;

            if (enableTrim && !processingState.manualBoundaries) {
                const detected = detectSilenceBoundaries(processingState.originalBuffer);
                trimStart = detected.start;
                trimEnd = detected.end;
            }

            let processedBuffer = processingState.originalBuffer;

            if (enableTrim) {
                processedBuffer = trimSilence(processingState.originalBuffer, trimStart, trimEnd);
            }

            if (enableNoise) {
                processedBuffer = await reduceNoise(processedBuffer);
            }

            if (enableLoudness) {
                processedBuffer = await normalizeLoudness(processedBuffer);
            }

            processingState.processedBuffer = processedBuffer;
            processingState.trimStart = trimStart;
            processingState.trimEnd = trimEnd;
            processingState.options = { enableTrim, enableNoise, enableLoudness };

            const waveformContainer = container.querySelector('.ll-waveform-container');
            if (waveformContainer) {
                waveformContainer.querySelectorAll('.ll-trim-boundary, .ll-trimmed-region').forEach(el => el.remove());
            }
            renderWaveform(container, processingState.originalBuffer, trimStart, trimEnd);
            setupAudioPlayback(container, processedBuffer);
            setupBoundaryDragging(container, processingState.originalBuffer);
        } catch (error) {
            console.error('Error reprocessing audio:', error);
            showStatus(i18n.processing_failed || 'Audio processing failed. You can upload the raw recording instead.', 'error');
        } finally {
            if (reprocessBtn) {
                reprocessBtn.textContent = originalText;
                reprocessBtn.disabled = false;
            }
        }
    }

    function detectSilenceBoundaries(audioBuffer) {
        const channelData = audioBuffer.getChannelData(0);
        const sampleRate = audioBuffer.sampleRate;
        const threshold = 0.02;
        const windowSize = Math.floor(0.01 * sampleRate);

        let startIndex = 0;
        for (let i = 0; i < channelData.length - windowSize; i++) {
            let sum = 0;
            for (let j = 0; j < windowSize; j++) {
                sum += Math.abs(channelData[i + j]);
            }
            const average = sum / windowSize;
            if (average > threshold) {
                startIndex = Math.max(0, i - Math.floor(0.1 * sampleRate));
                break;
            }
        }

        let endIndex = channelData.length;
        for (let i = channelData.length - windowSize; i >= 0; i--) {
            let sum = 0;
            for (let j = 0; j < windowSize; j++) {
                sum += Math.abs(channelData[i + j]);
            }
            const average = sum / windowSize;
            if (average > threshold) {
                endIndex = Math.min(channelData.length, i + windowSize + Math.floor(0.3 * sampleRate));
                break;
            }
        }

        return { start: startIndex, end: endIndex };
    }

    function trimSilence(audioBuffer, startIndex, endIndex) {
        if (endIndex <= startIndex) {
            return audioBuffer;
        }

        const trimmedLength = endIndex - startIndex;
        const context = getProcessingContext();
        const trimmedBuffer = context.createBuffer(
            audioBuffer.numberOfChannels,
            trimmedLength,
            audioBuffer.sampleRate
        );

        for (let channel = 0; channel < audioBuffer.numberOfChannels; channel++) {
            const sourceData = audioBuffer.getChannelData(channel);
            const targetData = trimmedBuffer.getChannelData(channel);
            for (let i = 0; i < trimmedLength; i++) {
                targetData[i] = sourceData[startIndex + i];
            }
        }

        return trimmedBuffer;
    }

    async function reduceNoise(audioBuffer) {
        const offlineContext = new OfflineAudioContext(
            audioBuffer.numberOfChannels,
            audioBuffer.length,
            audioBuffer.sampleRate
        );

        const source = offlineContext.createBufferSource();
        source.buffer = audioBuffer;

        const highpass = offlineContext.createBiquadFilter();
        highpass.type = 'highpass';
        highpass.frequency.value = 80;

        const lowpass = offlineContext.createBiquadFilter();
        lowpass.type = 'lowpass';
        lowpass.frequency.value = 8000;

        source.connect(highpass);
        highpass.connect(lowpass);
        lowpass.connect(offlineContext.destination);

        source.start();
        return await offlineContext.startRendering();
    }

    async function normalizeLoudness(audioBuffer) {
        const currentLUFS = calculateLUFS(audioBuffer);
        const targetGain = Math.pow(10, (-18.0 - currentLUFS) / 20);

        const context = getProcessingContext();
        const normalizedBuffer = context.createBuffer(
            audioBuffer.numberOfChannels,
            audioBuffer.length,
            audioBuffer.sampleRate
        );

        for (let channel = 0; channel < audioBuffer.numberOfChannels; channel++) {
            const sourceData = audioBuffer.getChannelData(channel);
            const targetData = normalizedBuffer.getChannelData(channel);
            for (let i = 0; i < sourceData.length; i++) {
                targetData[i] = Math.max(-1, Math.min(1, sourceData[i] * targetGain));
            }
        }

        return normalizedBuffer;
    }

    function calculateLUFS(audioBuffer) {
        const sampleRate = audioBuffer.sampleRate;
        const channelData = audioBuffer.getChannelData(0);

        const blockSize = Math.floor(0.4 * sampleRate);
        const hopSize = Math.floor(0.1 * sampleRate);

        const gatingThreshold = -70;
        const relativeThreshold = -10;

        let blockLoudnesses = [];

        for (let i = 0; i + blockSize < channelData.length; i += hopSize) {
            let sumSquares = 0;
            for (let j = 0; j < blockSize; j++) {
                const sample = channelData[i + j];
                sumSquares += sample * sample;
            }

            const meanSquare = sumSquares / blockSize;
            const loudness = -0.691 + 10 * Math.log10(meanSquare);

            if (loudness > gatingThreshold) {
                blockLoudnesses.push(loudness);
            }
        }

        if (blockLoudnesses.length === 0) {
            return -70;
        }

        const avgLoudness = blockLoudnesses.reduce((a, b) => a + b, 0) / blockLoudnesses.length;
        const relThreshold = avgLoudness + relativeThreshold;

        const gatedLoudnesses = blockLoudnesses.filter(l => l >= relThreshold);

        if (gatedLoudnesses.length === 0) {
            return avgLoudness;
        }

        const gatedAvg = gatedLoudnesses.reduce((a, b) => a + b, 0) / gatedLoudnesses.length;
        return gatedAvg;
    }

    function audioBufferToWav(audioBuffer) {
        const numChannels = audioBuffer.numberOfChannels;
        const sampleRate = audioBuffer.sampleRate;
        const format = 1;
        const bitDepth = 16;
        const bytesPerSample = bitDepth / 8;
        const blockAlign = numChannels * bytesPerSample;

        const data = [];
        for (let i = 0; i < audioBuffer.length; i++) {
            for (let channel = 0; channel < numChannels; channel++) {
                const sample = Math.max(-1, Math.min(1, audioBuffer.getChannelData(channel)[i]));
                data.push(sample < 0 ? sample * 0x8000 : sample * 0x7FFF);
            }
        }

        const dataLength = data.length * bytesPerSample;
        const buffer = new ArrayBuffer(44 + dataLength);
        const view = new DataView(buffer);

        const writeString = (offset, string) => {
            for (let i = 0; i < string.length; i++) {
                view.setUint8(offset + i, string.charCodeAt(i));
            }
        };

        writeString(0, 'RIFF');
        view.setUint32(4, 36 + dataLength, true);
        writeString(8, 'WAVE');
        writeString(12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, format, true);
        view.setUint16(22, numChannels, true);
        view.setUint32(24, sampleRate, true);
        view.setUint32(28, sampleRate * blockAlign, true);
        view.setUint16(32, blockAlign, true);
        view.setUint16(34, bitDepth, true);
        writeString(36, 'data');
        view.setUint32(40, dataLength, true);

        let offset = 44;
        for (let i = 0; i < data.length; i++) {
            view.setInt16(offset, data[i], true);
            offset += 2;
        }

        return new Blob([buffer], { type: 'audio/wav' });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    async function switchCategory() {
        const el = window.llRecorder;
        if (!el.categorySelect) return;
        if (newWordMode) return;

        const newCategory = el.categorySelect.value;
        if (!newCategory) return;

        showStatus(i18n.switching_category || 'Switching category...', 'info');
        el.categorySelect.disabled = true;
        el.recordBtn.disabled = true;
        el.skipBtn.disabled = true;
        if (el.hideBtn) el.hideBtn.disabled = true;

        const formData = new FormData();
        formData.append('action', 'll_get_images_for_recording');
        formData.append('nonce', nonce);
        formData.append('category', newCategory);
        formData.append('wordset_ids', JSON.stringify(window.ll_recorder_data?.wordset_ids || []));
        formData.append('include_types', window.ll_recorder_data?.include_types || '');
        formData.append('exclude_types', window.ll_recorder_data?.exclude_types || '');

        try {
            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error(i18n.invalid_response || 'Server returned invalid response format');
            }

            const data = await response.json();

            if (data.success) {
                const newImages = Array.isArray(data.data?.images) ? data.data.images : [];
                newImages.forEach(item => {
                    if (Array.isArray(item?.missing_types)) {
                        item.missing_types = sortRecordingTypes(item.missing_types);
                    }
                    if (Array.isArray(item?.existing_types)) {
                        item.existing_types = sortRecordingTypes(item.existing_types);
                    }
                });
                if (newImages.length === 0) {
                    // Mark this category as exhausted
                    exhaustedCategories.add(newCategory);

                    // This category has no images - try the next one
                    const nextCategory = getNextCategoryAfter(newCategory);
                    if (nextCategory && nextCategory.slug !== newCategory) {
                        el.categorySelect.value = nextCategory.slug;
                        el.categorySelect.disabled = false;
                        el.recordBtn.disabled = false;
                        el.skipBtn.disabled = false;
                        if (el.hideBtn) el.hideBtn.disabled = false;
                        // Recursively try the next category
                        return switchCategory();
                    } else {
                        // No more categories to try
                        showStatus(i18n.no_images_in_category || 'No images need audio in any remaining category.', 'error');
                        el.categorySelect.disabled = false;
                        if (el.hideBtn) el.hideBtn.disabled = false;
                        return;
                    }
                }

                // Update global images and reset indexer
                window.ll_recorder_data.images = newImages;
                images.length = 0;
                images.push(...newImages);
                currentImageIndex = 0;

                // Update recording type dropdown options
                const newTypes = sortRecordingTypes(data.data?.recording_types || []);
                updateRecordingTypeOptions(newTypes);

                // Reset and load first image
                if (el.completeScreen) el.completeScreen.style.display = 'none';
                if (el.mainScreen) el.mainScreen.style.display = 'flex';
                loadImage(0);

                showStatus(i18n.category_switched || 'Category switched. Ready to record.', 'success');
            } else {
                const errorMsg = data.data || data.message || 'Switch failed';
                showStatus((i18n.switch_failed || 'Switch failed:') + ' ' + errorMsg, 'error');
            }
        } catch (err) {
            console.error('Category switch error:', err);
            showStatus((i18n.switch_failed || 'Switch failed:') + ' ' + err.message, 'error');
        } finally {
            el.categorySelect.disabled = false;
            el.recordBtn.disabled = false;
            el.skipBtn.disabled = false;
            if (el.hideBtn) el.hideBtn.disabled = false;
        }
    }

    // Add this new helper function after getNextCategory()
    function getNextCategoryAfter(currentSlug) {
        const el = window.llRecorder;
        if (!el.categorySelect) return null;

        const availableCategories = window.ll_recorder_data?.available_categories || {};
        const categoryEntries = Object.entries(availableCategories);

        if (categoryEntries.length <= 1) return null;

        const currentIndex = categoryEntries.findIndex(([slug]) => slug === currentSlug);

        if (currentIndex === -1) return null;

        // Get next category, or wrap to first if at end
        const nextIndex = (currentIndex + 1) % categoryEntries.length;
        const [slug, name] = categoryEntries[nextIndex];

        return { slug, name };
    }

    function showComplete() {
        const el = window.llRecorder;
        if (newWordMode && newWordStage === 'recording') {
            newWordStage = 'setup';
            showNewWordPanel();
            return;
        }
        if (el.mainScreen) el.mainScreen.style.display = 'none';

        // Mark current category as exhausted since we completed all its images
        const currentCategory = el.categorySelect?.value;
        if (currentCategory) {
            exhaustedCategories.add(currentCategory);
        }

        if (el.completeScreen) {
            el.completeScreen.style.display = 'block';
            if (el.completedCount) el.completedCount.textContent = images.length;

            // Add next category button if there are other categories
            const nextCategory = getNextCategory();
            let nextCategoryBtn = el.completeScreen.querySelector('.ll-next-category-btn');

            if (nextCategory) {
                if (!nextCategoryBtn) {
                    nextCategoryBtn = document.createElement('button');
                    nextCategoryBtn.className = 'll-btn ll-btn-primary ll-next-category-btn';
                    nextCategoryBtn.style.marginTop = '20px';
                    nextCategoryBtn.innerHTML = `
                <span>${nextCategory.name}</span>
                <svg viewBox="0 0 24 24" style="width: 24px; height: 24px; margin-left: 8px;">
                    <path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z" fill="currentColor"/>
                </svg>
            `;
                    el.completeScreen.querySelector('p').insertAdjacentElement('afterend', nextCategoryBtn);

                    nextCategoryBtn.addEventListener('click', () => {
                        if (el.categorySelect) {
                            el.categorySelect.value = nextCategory.slug;
                            el.categorySelect.dispatchEvent(new Event('change'));
                        }
                    });
                } else {
                    // Update existing button with new category
                    nextCategoryBtn.innerHTML = `
                <span>${nextCategory.name}</span>
                <svg viewBox="0 0 24 24" style="width: 24px; height: 24px; margin-left: 8px;">
                    <path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z" fill="currentColor"/>
                </svg>
            `;
                }
            } else if (nextCategoryBtn) {
                // Remove button if no next category
                nextCategoryBtn.remove();
            }
        } else {
            const p = document.createElement('p');
            p.textContent = i18n.all_complete || 'All recordings completed for the selected set. Thank you!';
            document.querySelector('.ll-recording-wrapper')?.appendChild(p);
        }
    }

    function getNextCategory() {
        const el = window.llRecorder;
        if (!el.categorySelect) return null;

        const availableCategories = window.ll_recorder_data?.available_categories || {};
        const categoryEntries = Object.entries(availableCategories);

        if (categoryEntries.length <= 1) return null;

        const currentCategory = el.categorySelect.value;
        const currentIndex = categoryEntries.findIndex(([slug]) => slug === currentCategory);

        if (currentIndex === -1) return null;

        // Search for next non-exhausted category
        let attempts = 0;
        let nextIndex = currentIndex;

        while (attempts < categoryEntries.length) {
            nextIndex = (nextIndex + 1) % categoryEntries.length;
            const [slug, name] = categoryEntries[nextIndex];

            // Skip if this is the current category or if it's exhausted
            if (slug !== currentCategory && !exhaustedCategories.has(slug)) {
                return { slug, name };
            }

            attempts++;
        }

        // All categories are exhausted
        return null;
    }

})();
