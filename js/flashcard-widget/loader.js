/**
 * flashcard-loader.js
 *
 * Handles loading of resources (audio, images) for flashcards.
 */
(function ($) {
    /**
     * FlashcardLoader Module
     * 
     * Manages the loading of audio and image resources for flashcards, ensuring efficient preloading and organization by category.
     */
    const FlashcardLoader = (function () {
        // Tracks loaded categories and resources
        const loadedCategories = [];
        const loadedResources = {};
        const inFlightRequests = {};
        const inFlightMediaLoads = {};
        const categoryAjaxQueue = [];
        let activeCategoryAjaxRequests = 0;
        let categoryAjaxQueueTimerId = null;
        let lastCategoryAjaxStartedAt = 0;
        let requestSerial = 0;
        let lastWordsetKey = null;
        const defaultConfig = { prompt_type: 'audio', option_type: 'image' };
        const AUDIO_LOAD_TIMEOUT_MS = 4500;
        const AUDIO_LOAD_MAX_RETRIES = 1;
        const IMAGE_LOAD_TIMEOUT_MS = 3500;
        const IMAGE_LOAD_MAX_RETRIES = 1;
        function getAllowedWordsetIds() {
            const ids = (window.llToolsFlashcardsData && Array.isArray(window.llToolsFlashcardsData.wordsetIds))
                ? window.llToolsFlashcardsData.wordsetIds
                : [];
            return ids.map(function (v) { return parseInt(v, 10); }).filter(function (v) { return v > 0 && isFinite(v); });
        }
        function getSessionWordLookup() {
            const data = window.llToolsFlashcardsData || {};
            const raw = Array.isArray(data.sessionWordIds)
                ? data.sessionWordIds
                : (Array.isArray(data.session_word_ids) ? data.session_word_ids : []);
            const lookup = {};
            let count = 0;
            raw.forEach(function (value) {
                const id = parseInt(value, 10);
                if (id > 0 && isFinite(id) && !lookup[id]) {
                    lookup[id] = true;
                    count++;
                }
            });
            return count > 0 ? lookup : null;
        }

        function getCategoryConfig(name) {
            const cats = (window.llToolsFlashcardsData && Array.isArray(window.llToolsFlashcardsData.categories))
                ? window.llToolsFlashcardsData.categories
                : [];
            const found = cats.find(c => c && c.name === name);
            return Object.assign({}, defaultConfig, found || {});
        }

        function categoryRequiresAudio(config) {
            const opt = config.option_type || config.mode;
            return (config.prompt_type === 'audio') || opt === 'audio' || opt === 'text_audio';
        }

        function categoryRequiresImage(config, displayMode) {
            const opt = displayMode || config.option_type || config.mode;
            return (config.prompt_type === 'image') || opt === 'image';
        }

        function getWordsetKey() {
            const ws = (window.llToolsFlashcardsData && typeof window.llToolsFlashcardsData.wordset !== 'undefined')
                ? window.llToolsFlashcardsData.wordset
                : '';
            const fallback = (window.llToolsFlashcardsData && typeof window.llToolsFlashcardsData.wordsetFallback !== 'undefined')
                ? !!window.llToolsFlashcardsData.wordsetFallback
                : true;
            const sessionRaw = (window.llToolsFlashcardsData && Array.isArray(window.llToolsFlashcardsData.sessionWordIds))
                ? window.llToolsFlashcardsData.sessionWordIds
                : ((window.llToolsFlashcardsData && Array.isArray(window.llToolsFlashcardsData.session_word_ids))
                    ? window.llToolsFlashcardsData.session_word_ids
                    : []);
            const sessionKey = sessionRaw
                .map(function (id) { return parseInt(id, 10) || 0; })
                .filter(function (id) { return id > 0; })
                .sort(function (a, b) { return a - b; })
                .join(',');
            return String(ws || '') + '|' + (fallback ? '1' : '0') + '|' + (sessionKey || 'all');
        }

        function resetCacheForNewWordset() {
            loadedCategories.length = 0;
            Object.keys(loadedResources).forEach(function (k) { delete loadedResources[k]; });
            Object.keys(inFlightRequests).forEach(function (k) { delete inFlightRequests[k]; });
            Object.keys(inFlightMediaLoads).forEach(function (k) { delete inFlightMediaLoads[k]; });
            if (window.optionWordsByCategory && typeof window.optionWordsByCategory === 'object') {
                Object.keys(window.optionWordsByCategory).forEach(function (k) { delete window.optionWordsByCategory[k]; });
            }
            lastWordsetKey = null;
        }

        function ensureWordsetCacheKey() {
            const key = getWordsetKey();
            if (key !== lastWordsetKey) {
                resetCacheForNewWordset();
                lastWordsetKey = key;
            }
            return key;
        }

        /**
         * Randomly sorts an array.
         *
         * @param {Array} inputArray - The array to sort.
         * @returns {Array} A new randomly sorted array.
         */
        function randomlySort(inputArray) {
            if (!Array.isArray(inputArray)) {
                return inputArray;
            }
            return [...inputArray].sort(() => 0.5 - Math.random());
        }

        function getRetryCount(raw, fallback) {
            const parsed = parseInt(raw, 10);
            if (!Number.isFinite(parsed)) return fallback;
            return Math.max(0, Math.min(parsed, 5));
        }

        function getTimeoutMs(raw, fallback, min, max) {
            const parsed = parseInt(raw, 10);
            if (!Number.isFinite(parsed)) return fallback;
            return Math.max(min, Math.min(parsed, max));
        }

        function getRetryDelayMs(attempt) {
            const step = Math.max(1, parseInt(attempt, 10) || 1);
            return Math.min(1200, (170 * step) + Math.round(Math.random() * 140));
        }

        function getClampedInt(raw, fallback, min, max) {
            const parsed = parseInt(raw, 10);
            if (!Number.isFinite(parsed)) {
                return fallback;
            }
            return Math.max(min, Math.min(max, parsed));
        }

        function getPreloadTuning() {
            const tuning = (window.llToolsFlashcardsData && window.llToolsFlashcardsData.preloadTuning && typeof window.llToolsFlashcardsData.preloadTuning === 'object')
                ? window.llToolsFlashcardsData.preloadTuning
                : {};

            return {
                categoryAjaxConcurrency: getClampedInt(tuning.categoryAjaxConcurrency, 1, 1, 4),
                categoryAjaxSpacingMs: getClampedInt(tuning.categoryAjaxSpacingMs, 220, 0, 6000),
                categoryAjaxMaxRetriesOn429: getClampedInt(tuning.categoryAjaxMaxRetriesOn429, 2, 0, 6),
                categoryAjaxRetryBaseMs: getClampedInt(tuning.categoryAjaxRetryBaseMs, 900, 100, 30000),
                categoryAjaxRetryMaxMs: getClampedInt(tuning.categoryAjaxRetryMaxMs, 10000, 500, 90000),
                categoryMediaChunkSize: getClampedInt(tuning.categoryMediaChunkSize, 8, 1, 30),
                categoryMediaChunkDelayMs: getClampedInt(tuning.categoryMediaChunkDelayMs, 100, 0, 10000),
                categoryMediaChunkConcurrency: getClampedInt(tuning.categoryMediaChunkConcurrency, 2, 1, 8)
            };
        }

        function wait(ms) {
            return new Promise(function (resolve) {
                setTimeout(resolve, Math.max(0, parseInt(ms, 10) || 0));
            });
        }

        function scheduleCategoryAjaxQueuePump(delayMs) {
            if (categoryAjaxQueueTimerId) {
                return;
            }
            categoryAjaxQueueTimerId = setTimeout(function () {
                categoryAjaxQueueTimerId = null;
                pumpCategoryAjaxQueue();
            }, Math.max(0, parseInt(delayMs, 10) || 0));
        }

        function pumpCategoryAjaxQueue() {
            if (!categoryAjaxQueue.length) {
                return;
            }

            const tuning = getPreloadTuning();
            if (activeCategoryAjaxRequests >= tuning.categoryAjaxConcurrency) {
                return;
            }

            const now = Date.now();
            const spacingMs = Math.max(0, tuning.categoryAjaxSpacingMs || 0);
            const nextAllowedStart = lastCategoryAjaxStartedAt + spacingMs;
            if (spacingMs > 0 && nextAllowedStart > now) {
                scheduleCategoryAjaxQueuePump(nextAllowedStart - now);
                return;
            }

            const job = categoryAjaxQueue.shift();
            if (!job || typeof job.run !== 'function') {
                pumpCategoryAjaxQueue();
                return;
            }

            activeCategoryAjaxRequests += 1;
            lastCategoryAjaxStartedAt = Date.now();

            Promise.resolve()
                .then(job.run)
                .then(job.resolve, job.reject)
                .finally(function () {
                    activeCategoryAjaxRequests = Math.max(0, activeCategoryAjaxRequests - 1);
                    pumpCategoryAjaxQueue();
                });

            if (activeCategoryAjaxRequests < tuning.categoryAjaxConcurrency) {
                pumpCategoryAjaxQueue();
            }
        }

        function enqueueCategoryAjaxRequest(run) {
            return new Promise(function (resolve, reject) {
                categoryAjaxQueue.push({
                    run: run,
                    resolve: resolve,
                    reject: reject
                });
                pumpCategoryAjaxQueue();
            });
        }

        function parseRetryAfterMs(xhr) {
            if (!xhr || typeof xhr.getResponseHeader !== 'function') {
                return 0;
            }
            let raw = '';
            try {
                raw = String(xhr.getResponseHeader('Retry-After') || '').trim();
            } catch (_) {
                raw = '';
            }
            if (!raw) {
                return 0;
            }
            const seconds = parseFloat(raw);
            if (Number.isFinite(seconds)) {
                return Math.max(0, Math.round(seconds * 1000));
            }
            const retryAt = Date.parse(raw);
            if (!Number.isFinite(retryAt)) {
                return 0;
            }
            return Math.max(0, retryAt - Date.now());
        }

        function getCategoryAjaxRetryDelayMs(xhr, attempt) {
            const tuning = getPreloadTuning();
            const retryAfterMs = parseRetryAfterMs(xhr);
            if (retryAfterMs > 0) {
                return Math.min(tuning.categoryAjaxRetryMaxMs, retryAfterMs);
            }
            const step = Math.max(1, parseInt(attempt, 10) || 1);
            const base = Math.max(100, tuning.categoryAjaxRetryBaseMs || 900);
            const jitter = Math.round(Math.random() * 250);
            return Math.min(tuning.categoryAjaxRetryMaxMs, (base * Math.pow(2, step - 1)) + jitter);
        }

        function performAudioLoadAttempt(audioURL, timeoutMs) {
            return new Promise((resolve) => {
                let audio = document.createElement('audio');
                let settled = false;
                let timeoutId = null;

                const onPlayable = function () { settle(true); };
                const onFailure = function () { settle(false); };

                const detach = function () {
                    try { audio.removeEventListener('canplaythrough', onPlayable); } catch (_) { /* no-op */ }
                    try { audio.removeEventListener('canplay', onPlayable); } catch (_) { /* no-op */ }
                    try { audio.removeEventListener('loadeddata', onPlayable); } catch (_) { /* no-op */ }
                    try { audio.removeEventListener('error', onFailure); } catch (_) { /* no-op */ }
                    try { audio.removeEventListener('stalled', onFailure); } catch (_) { /* no-op */ }
                    try { audio.removeEventListener('abort', onFailure); } catch (_) { /* no-op */ }
                };

                const settle = function (ready) {
                    if (settled) { return; }
                    settled = true;
                    if (timeoutId) {
                        clearTimeout(timeoutId);
                        timeoutId = null;
                    }
                    detach();
                    cleanupAudio(audio);
                    audio = null;
                    resolve(!!ready);
                };

                audio.crossOrigin = 'anonymous';
                audio.preload = 'auto';

                audio.addEventListener('canplaythrough', onPlayable, { once: true });
                audio.addEventListener('canplay', onPlayable, { once: true });
                audio.addEventListener('loadeddata', onPlayable, { once: true });
                audio.addEventListener('error', onFailure, { once: true });
                audio.addEventListener('stalled', onFailure, { once: true });
                audio.addEventListener('abort', onFailure, { once: true });

                timeoutId = setTimeout(function () {
                    const canPlay = !!(audio && typeof audio.readyState === 'number' && audio.readyState >= 2 && !audio.error);
                    settle(canPlay);
                }, timeoutMs);

                try {
                    audio.src = audioURL;
                    audio.load();
                    if (audio.readyState >= 2 && !audio.error) {
                        settle(true);
                    }
                } catch (_) {
                    settle(false);
                }
            });
        }

        /**
         * Loads an audio file and marks it as loaded.
         *
         * @param {string} audioURL - The URL of the audio file.
         * @param {Object} options - Retry/timeout options.
         * @returns {Promise<Object>} Resolves with readiness metadata.
         */
        function loadAudio(audioURL, options) {
            const opts = options || {};
            const forceRetry = opts.forceRetry === true;
            if (!audioURL) {
                return Promise.resolve({
                    ready: false,
                    missing: true,
                    attempts: 0,
                    url: ''
                });
            }
            if (!forceRetry && loadedResources[audioURL]) {
                return Promise.resolve({
                    ready: true,
                    cached: true,
                    attempts: 0,
                    url: audioURL
                });
            }
            if (!forceRetry && inFlightMediaLoads[audioURL]) {
                return inFlightMediaLoads[audioURL];
            }

            const maxRetries = getRetryCount(opts.maxRetries, AUDIO_LOAD_MAX_RETRIES);
            const timeoutMs = getTimeoutMs(opts.timeoutMs, AUDIO_LOAD_TIMEOUT_MS, 900, 20000);
            let attempt = 0;

            const runAttempt = function () {
                attempt += 1;
                return performAudioLoadAttempt(audioURL, timeoutMs).then(function (ready) {
                    if (ready) {
                        loadedResources[audioURL] = true;
                        return {
                            ready: true,
                            attempts: attempt,
                            url: audioURL
                        };
                    }

                    if (attempt <= maxRetries) {
                        const retryDelay = getRetryDelayMs(attempt);
                        return new Promise(function (resolve) {
                            setTimeout(resolve, retryDelay);
                        }).then(runAttempt);
                    }

                    delete loadedResources[audioURL];
                    return {
                        ready: false,
                        attempts: attempt,
                        url: audioURL
                    };
                });
            };

            let loadPromise = runAttempt().catch(function () {
                delete loadedResources[audioURL];
                return {
                    ready: false,
                    attempts: Math.max(1, attempt),
                    url: audioURL
                };
            });

            if (!forceRetry) {
                inFlightMediaLoads[audioURL] = loadPromise;
            }

            loadPromise = loadPromise.finally(function () {
                if (inFlightMediaLoads[audioURL] === loadPromise) {
                    delete inFlightMediaLoads[audioURL];
                }
            });

            if (!forceRetry) {
                inFlightMediaLoads[audioURL] = loadPromise;
            }

            return loadPromise;
        }

        /**
         * Cleans up an Audio object to free memory.
         *
         * @param {HTMLAudioElement} audio - The audio element to clean up.
         */
        function cleanupAudio(audio) {
            if (!audio) return;
            try { audio.pause(); } catch (_) { /* no-op */ }
            try { audio.removeAttribute('src'); } catch (_) { /* no-op */ }
            try { audio.load(); } catch (_) { /* no-op */ }
            // If for any reason it was added to the DOM, remove it.
            if (audio.parentNode) {
                audio.parentNode.removeChild(audio);
            }
        }

        function performImageLoadAttempt(imageURL, timeoutMs) {
            return new Promise((resolve) => {
                let img = new Image();
                let settled = false;
                let timeoutId = null;

                const detach = function () {
                    img.onload = null;
                    img.onerror = null;
                };

                const settle = function (ready) {
                    if (settled) { return; }
                    settled = true;
                    if (timeoutId) {
                        clearTimeout(timeoutId);
                        timeoutId = null;
                    }
                    detach();
                    resolve(!!ready);
                };

                img.onload = function () { settle(true); };
                img.onerror = function () { settle(false); };
                timeoutId = setTimeout(function () {
                    const ready = !!(img && img.complete && img.naturalWidth > 0);
                    settle(ready);
                }, timeoutMs);

                try {
                    img.src = imageURL;
                    if (img.complete && img.naturalWidth > 0) {
                        settle(true);
                    }
                } catch (_) {
                    settle(false);
                }
            });
        }

        /**
         * Loads an image file and marks it as loaded.
         *
         * @param {string} imageURL - The URL of the image file.
         * @param {Object} options - Retry/timeout options.
         * @returns {Promise<Object>} Resolves with readiness metadata.
         */
        function loadImage(imageURL, options) {
            const opts = options || {};
            const forceRetry = opts.forceRetry === true;
            if (!imageURL) {
                return Promise.resolve({
                    ready: false,
                    missing: true,
                    attempts: 0,
                    url: ''
                });
            }
            if (!forceRetry && loadedResources[imageURL]) {
                return Promise.resolve({
                    ready: true,
                    cached: true,
                    attempts: 0,
                    url: imageURL
                });
            }
            if (!forceRetry && inFlightMediaLoads[imageURL]) {
                return inFlightMediaLoads[imageURL];
            }

            const maxRetries = getRetryCount(opts.maxRetries, IMAGE_LOAD_MAX_RETRIES);
            const timeoutMs = getTimeoutMs(opts.timeoutMs, IMAGE_LOAD_TIMEOUT_MS, 800, 15000);
            let attempt = 0;

            const runAttempt = function () {
                attempt += 1;
                return performImageLoadAttempt(imageURL, timeoutMs).then(function (ready) {
                    if (ready) {
                        loadedResources[imageURL] = true;
                        return {
                            ready: true,
                            attempts: attempt,
                            url: imageURL
                        };
                    }

                    if (attempt <= maxRetries) {
                        const retryDelay = getRetryDelayMs(attempt);
                        return new Promise(function (resolve) {
                            setTimeout(resolve, retryDelay);
                        }).then(runAttempt);
                    }

                    delete loadedResources[imageURL];
                    return {
                        ready: false,
                        attempts: attempt,
                        url: imageURL
                    };
                });
            };

            let loadPromise = runAttempt().catch(function () {
                delete loadedResources[imageURL];
                return {
                    ready: false,
                    attempts: Math.max(1, attempt),
                    url: imageURL
                };
            });

            if (!forceRetry) {
                inFlightMediaLoads[imageURL] = loadPromise;
            }

            loadPromise = loadPromise.finally(function () {
                if (inFlightMediaLoads[imageURL] === loadPromise) {
                    delete inFlightMediaLoads[imageURL];
                }
            });

            if (!forceRetry) {
                inFlightMediaLoads[imageURL] = loadPromise;
            }

            return loadPromise;
        }

        /**
         * Processes fetched word data and organizes it by category.
         *
         * @param {Array} wordData - Array of word objects.
         * @param {string} categoryName - The name of the category.
         */
        function processFetchedWordData(wordData, categoryName) {
            // IMPORTANT: Replace existing data instead of appending to avoid mixing wordsets
            window.wordsByCategory[categoryName] = [];
            window.optionWordsByCategory = window.optionWordsByCategory || {};
            window.optionWordsByCategory[categoryName] = [];

            if (!window.categoryRoundCount[categoryName]) {
                window.categoryRoundCount[categoryName] = 0;
            }

            const allowedWordsetIds = getAllowedWordsetIds();
            const hasWordsetFilter = allowedWordsetIds.length > 0;
            const sessionWordLookup = getSessionWordLookup();

            const cfg = getCategoryConfig(categoryName);
            const needsAudio = categoryRequiresAudio(cfg);
            const optionType = cfg.option_type || cfg.mode || '';
            const optionRequiresAudio = optionType === 'audio' || optionType === 'text_audio';
            const parseBool = function (raw) {
                if (typeof raw === 'boolean') return raw;
                if (typeof raw === 'number') return raw > 0;
                if (typeof raw === 'string') {
                    const normalized = raw.trim().toLowerCase();
                    if (normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'on') return true;
                    if (normalized === '0' || normalized === 'false' || normalized === 'no' || normalized === 'off' || normalized === '') return false;
                }
                return !!raw;
            };
            const isSpecificWrongOnlyWord = function (word) {
                if (!word || typeof word !== 'object') return false;
                if (Object.prototype.hasOwnProperty.call(word, 'is_specific_wrong_answer_only')) {
                    return parseBool(word.is_specific_wrong_answer_only);
                }
                const ownerIds = Array.isArray(word.specific_wrong_answer_owner_ids) ? word.specific_wrong_answer_owner_ids.filter(Boolean) : [];
                const ownIds = Array.isArray(word.specific_wrong_answer_ids) ? word.specific_wrong_answer_ids.filter(Boolean) : [];
                const ownTexts = Array.isArray(word.specific_wrong_answer_texts) ? word.specific_wrong_answer_texts.filter(Boolean) : [];
                return ownerIds.length > 0 && ownIds.length === 0 && ownTexts.length === 0;
            };

            // Filter out words that do not have a resolvable audio URL
            const filteredByCategory = Array.isArray(wordData) ? wordData.map(function (w) {
                const url = resolvePlayableAudio(w);
                if (url) {
                    w.audio = url;
                    w.has_audio = true;
                } else {
                    w.has_audio = !!w.audio;
                }
                w.has_image = w.has_image || !!w.image;
                return w;
            }).filter(function (w) {
                if (needsAudio && !w.audio) {
                    const allowWrongOnlyNoAudio = !optionRequiresAudio && isSpecificWrongOnlyWord(w);
                    if (!allowWrongOnlyNoAudio) return false;
                }
                if (hasWordsetFilter) {
                    const ids = Array.isArray(w.wordset_ids) ? w.wordset_ids : [];
                    const match = ids.some(function (id) { return allowedWordsetIds.indexOf(parseInt(id, 10)) !== -1; });
                    if (!match) return false;
                }
                return true;
            }) : [];

            const filteredBySession = sessionWordLookup
                ? filteredByCategory.filter(function (w) {
                    const wordId = parseInt(w && w.id, 10);
                    return !!wordId && !!sessionWordLookup[wordId];
                })
                : filteredByCategory.slice();

            window.optionWordsByCategory[categoryName].push(...filteredByCategory);
            window.optionWordsByCategory[categoryName] = randomlySort(window.optionWordsByCategory[categoryName]);

            window.wordsByCategory[categoryName].push(...filteredBySession);
            window.wordsByCategory[categoryName] = randomlySort(window.wordsByCategory[categoryName]);
        }

        /**
         * Loads resources for a specific category via AJAX.
         *
         * @param {string} categoryName - The name of the category.
         * @param {function} callback - Callback to execute after loading.
         */
        function loadResourcesForCategory(categoryName, callback, options) {
            const opts = options || {};
            const earlyCallback = opts.earlyCallback === true;
            const skipCategoryPreload = opts.skipCategoryPreload === true;
            const wordsetKey = ensureWordsetCacheKey();
            const cacheKey = wordsetKey + '::' + categoryName;

            if (loadedCategories.includes(cacheKey)) {
                if (typeof callback === 'function') callback();
                return Promise.resolve({ cached: true, category: categoryName });
            }

            const displayMode = window.getCategoryDisplayMode(categoryName);
            const cfg = getCategoryConfig(categoryName);
            const wordset = window.llToolsFlashcardsData?.wordset || '';
            const wsFallback = !!(window.llToolsFlashcardsData && window.llToolsFlashcardsData.wordsetFallback);
            const nonce = (window.llToolsFlashcardsData && (window.llToolsFlashcardsData.ajaxNonce || window.llToolsFlashcardsData._ajax_nonce || window.llToolsFlashcardsData.nonce)) || '';

            const payload = {
                action: 'll_get_words_by_category',
                category: categoryName,
                display_mode: displayMode,
                wordset: wordset,  // This ensures wordset is always passed
                wordset_fallback: wsFallback ? '1' : '0',
                prompt_type: cfg.prompt_type || 'audio',
                option_type: cfg.option_type || displayMode
            };
            if (nonce) {
                payload._ajax_nonce = nonce;
            }
            const requestId = ++requestSerial;
            let callbackInvoked = false;

            function invokeCallbackOnce() {
                if (callbackInvoked) {
                    return;
                }
                callbackInvoked = true;
                if (typeof callback === 'function') {
                    try { callback(); } catch (_) { /* no-op */ }
                }
            }

            function isStaleRequest() {
                return wordsetKey !== getWordsetKey() || inFlightRequests[cacheKey] !== requestId;
            }

            function recordAjaxStart(attempt) {
                try {
                    window.__LL_LAST_WORDS_AJAX = {
                        startedAt: Date.now(),
                        category: categoryName,
                        attempt: attempt,
                        url: (window.llToolsFlashcardsData && window.llToolsFlashcardsData.ajaxurl) ? window.llToolsFlashcardsData.ajaxurl : '',
                        page: window.location && window.location.href ? window.location.href : '',
                        payload: Object.assign({}, payload)
                    };
                } catch (_) {}
            }

            function recordAjaxCompletion(response) {
                try {
                    if (window.__LL_LAST_WORDS_AJAX) {
                        window.__LL_LAST_WORDS_AJAX.endedAt = Date.now();
                        window.__LL_LAST_WORDS_AJAX.response = response;
                    }
                } catch (_) {}
            }

            function recordAjaxFailure(xhr, status, error, extra) {
                try {
                    if (window.__LL_LAST_WORDS_AJAX) {
                        window.__LL_LAST_WORDS_AJAX.endedAt = Date.now();
                        window.__LL_LAST_WORDS_AJAX.error = Object.assign({
                            status: status,
                            error: error,
                            httpStatus: xhr && typeof xhr.status !== 'undefined' ? xhr.status : null,
                            responseText: xhr && typeof xhr.responseText === 'string' ? xhr.responseText : ''
                        }, extra || {});
                    }
                } catch (_) {}
            }

            function runAjaxAttempt(attempt) {
                const attemptNumber = Math.max(1, parseInt(attempt, 10) || 1);
                if (loadedCategories.includes(cacheKey)) {
                    if (inFlightRequests[cacheKey] === requestId) {
                        delete inFlightRequests[cacheKey];
                    }
                    invokeCallbackOnce();
                    return Promise.resolve({ cached: true, category: categoryName });
                }

                if (wordsetKey !== getWordsetKey()) {
                    if (inFlightRequests[cacheKey] === requestId) {
                        delete inFlightRequests[cacheKey];
                    }
                    return Promise.resolve({ stale: true, category: categoryName });
                }

                inFlightRequests[cacheKey] = requestId;
                recordAjaxStart(attemptNumber);

                return new Promise(function (resolve) {
                    $.ajax({
                        url: llToolsFlashcardsData.ajaxurl,
                        method: 'POST',
                        dataType: 'json',
                        data: payload,
                        success: function (response) {
                            // Ignore stale responses from previous wordset/session requests.
                            if (isStaleRequest()) {
                                resolve({ stale: true, category: categoryName });
                                return;
                            }
                            delete inFlightRequests[cacheKey];
                            recordAjaxCompletion(response);
                            if (response.success) {
                                processFetchedWordData(response.data, categoryName);
                                if (earlyCallback) {
                                    // Let the caller continue as soon as data is available; optional preload continues in background.
                                    invokeCallbackOnce();
                                    if (!skipCategoryPreload) {
                                        preloadCategoryResources(categoryName);
                                    }
                                } else if (!skipCategoryPreload) {
                                    preloadCategoryResources(categoryName, invokeCallbackOnce);
                                } else {
                                    invokeCallbackOnce();
                                }
                                if (!loadedCategories.includes(cacheKey)) {
                                    loadedCategories.push(cacheKey);
                                }
                            } else {
                                console.error('Failed to load words for category:', categoryName, response);
                                invokeCallbackOnce();
                            }
                            resolve({ success: !!response.success, category: categoryName });
                        },
                        error: function (xhr, status, error) {
                            if (isStaleRequest()) {
                                resolve({ stale: true, category: categoryName });
                                return;
                            }

                            const httpStatus = xhr && typeof xhr.status !== 'undefined' ? xhr.status : null;
                            const retryCfg = getPreloadTuning();
                            const canRetry429 = httpStatus === 429 && attemptNumber <= retryCfg.categoryAjaxMaxRetriesOn429;
                            if (canRetry429) {
                                const retryDelayMs = getCategoryAjaxRetryDelayMs(xhr, attemptNumber);
                                recordAjaxFailure(xhr, status, error, {
                                    retrying: true,
                                    retryDelayMs: retryDelayMs
                                });
                                console.warn('AJAX rate-limited for category; retrying with backoff:', categoryName, {
                                    attempt: attemptNumber,
                                    retryDelayMs: retryDelayMs,
                                    httpStatus: httpStatus
                                });
                                resolve(wait(retryDelayMs).then(function () {
                                    return runAjaxAttempt(attemptNumber + 1);
                                }));
                                return;
                            }

                            delete inFlightRequests[cacheKey];
                            recordAjaxFailure(xhr, status, error);
                            console.error('AJAX request failed for category:', categoryName, {
                                status: status,
                                error: error,
                                httpStatus: httpStatus,
                                responseText: xhr && typeof xhr.responseText === 'string' ? xhr.responseText : ''
                            });
                            invokeCallbackOnce();
                            resolve({
                                success: false,
                                category: categoryName,
                                httpStatus: httpStatus
                            });
                        }
                    });
                });
            }

            return enqueueCategoryAjaxRequest(function () {
                if (loadedCategories.includes(cacheKey)) {
                    invokeCallbackOnce();
                    return Promise.resolve({ cached: true, category: categoryName });
                }
                return runAjaxAttempt(1);
            }).catch(function (err) {
                console.error('Category request queue failed for category:', categoryName, err);
                if (inFlightRequests[cacheKey] === requestId) {
                    delete inFlightRequests[cacheKey];
                }
                invokeCallbackOnce();
                return { success: false, category: categoryName };
            });
        }

        /**
         * Preloads resources for a category in chunks to optimize performance,
         * but doesn't block the user from starting the quiz after the first chunk.
         *
         * @param {string} categoryName - The name of the category.
         * @param {function} onFirstChunkLoaded - Callback invoked once the first chunk has finished loading.
         */
        function preloadCategoryResources(categoryName, onFirstChunkLoaded) {
            const words = window.wordsByCategory[categoryName] || [];
            const totalWords = words.length;
            if (totalWords === 0) {
                if (typeof onFirstChunkLoaded === 'function') {
                    onFirstChunkLoaded();
                }
                return;
            }

            const tuning = getPreloadTuning();
            const chunkSize = Math.max(1, Math.min(totalWords, tuning.categoryMediaChunkSize));
            const chunkDelayMs = Math.max(0, tuning.categoryMediaChunkDelayMs || 0);
            let currentIndex = 0;
            const displayMode = window.getCategoryDisplayMode(categoryName);
            const cfg = getCategoryConfig(categoryName);
            const needsAudio = categoryRequiresAudio(cfg);
            const needsImage = categoryRequiresImage(cfg, displayMode);

            let hasTriggeredFirstChunkLoad = false; // Ensure we only fire onFirstChunkLoaded once

            function loadNextChunk() {
                if (currentIndex >= totalWords) {
                    // All chunks loaded, we're done.
                    return;
                }

                // Get the slice of words for this chunk
                const end = Math.min(currentIndex + chunkSize, totalWords);
                const chunk = words.slice(currentIndex, end);

                const chunkWorkerCount = Math.max(1, Math.min(chunk.length, tuning.categoryMediaChunkConcurrency));
                let chunkCursor = 0;

                const loadChunk = function () {
                    const workers = [];
                    const runWorker = function () {
                        if (chunkCursor >= chunk.length) {
                            return Promise.resolve();
                        }
                        const index = chunkCursor;
                        chunkCursor += 1;
                        const word = chunk[index];
                        return Promise.all([
                            (needsAudio && word && word.audio ? loadAudio(word.audio) : Promise.resolve()),
                            (needsImage && word && word.image ? loadImage(word.image) : Promise.resolve())
                        ]).catch(function () {
                            return [];
                        }).then(runWorker);
                    };

                    for (let i = 0; i < chunkWorkerCount; i += 1) {
                        workers.push(runWorker());
                    }
                    return Promise.all(workers);
                };

                // Once all items in the chunk are loaded (or failed), move to the next chunk
                Promise.resolve(loadChunk()).catch(function () {
                    return [];
                }).then(() => {
                    currentIndex = end;

                    // After loading the first chunk, proceed with the callback function
                    if (!hasTriggeredFirstChunkLoad) {
                        hasTriggeredFirstChunkLoad = true;
                        if (typeof onFirstChunkLoaded === 'function') {
                            onFirstChunkLoaded();
                        }

                    }

                    // Continue loading subsequent chunks in the background
                    if (chunkDelayMs > 0) {
                        setTimeout(loadNextChunk, chunkDelayMs);
                    } else {
                        loadNextChunk();
                    }
                });
            }

            // Start loading the first chunk
            loadNextChunk();
        }

        /**
         * Preloads the next set of categories.
         *
         * @param {number} numberToPreload - Number of categories to preload.
         */
        function preloadNextCategories(numberToPreload = 3) {
            window.categoryNames.forEach(function (categoryName) {
                const cacheKey = ensureWordsetCacheKey() + '::' + categoryName;
                if (!loadedCategories.includes(cacheKey) && numberToPreload > 0) {
                    loadResourcesForCategory(categoryName);
                    numberToPreload--;
                }
            });
        }

        /**
         * Loads resources for a specific word based on display mode.
         *
         * @param {Object} word - The word object.
         * @param {string} displayMode - The display mode ('image' or 'text').
         * @returns {Promise<Object>} Resolves with per-media readiness.
         */
        function loadResourcesForWord(word, displayMode, categoryName, categoryConfig, options) {
            if (!word) {
                return Promise.resolve({
                    ready: false,
                    missingWord: true,
                    audioReady: false,
                    imageReady: false
                });
            }
            const opts = options || {};
            const cfg = categoryConfig || getCategoryConfig(categoryName || (window.LLFlashcards && window.LLFlashcards.State && window.LLFlashcards.State.currentCategoryName) || '');
            const needsAudio = categoryRequiresAudio(cfg);
            const needsImage = categoryRequiresImage(cfg, displayMode);
            const shouldPreloadImage = needsImage && !opts.skipImagePreload;
            const audioURL = (typeof word.audio === 'string') ? word.audio : '';
            const imageURL = (typeof word.image === 'string') ? word.image : '';
            const audioPromise = needsAudio
                ? (audioURL
                    ? loadAudio(audioURL, {
                        maxRetries: opts.audioRetryCount,
                        timeoutMs: opts.audioTimeoutMs,
                        forceRetry: opts.forceAudioRetry === true
                    })
                    : Promise.resolve({
                        ready: false,
                        missing: true,
                        attempts: 0,
                        url: ''
                    }))
                : Promise.resolve({
                    ready: true,
                    skipped: true,
                    attempts: 0,
                    url: audioURL
                });
            const imagePromise = shouldPreloadImage
                ? (imageURL
                    ? loadImage(imageURL, {
                        maxRetries: opts.imageRetryCount,
                        timeoutMs: opts.imageTimeoutMs,
                        forceRetry: opts.forceImageRetry === true
                    })
                    : Promise.resolve({
                        ready: false,
                        missing: true,
                        attempts: 0,
                        url: ''
                    }))
                : Promise.resolve({
                    ready: true,
                    skipped: true,
                    attempts: 0,
                    url: imageURL
                });
            const preloader = Promise.all([
                audioPromise,
                imagePromise
            ]).then(function (results) {
                const audioResult = results[0] || { ready: !needsAudio };
                const imageResult = results[1] || { ready: !shouldPreloadImage };
                const audioReady = !needsAudio || !!audioResult.ready;
                const imageReady = !shouldPreloadImage || !!imageResult.ready;

                return {
                    ready: audioReady && imageReady,
                    audioReady: audioReady,
                    imageReady: imageReady,
                    audio: audioResult,
                    image: imageResult,
                    wordId: parseInt(word.id, 10) || word.id
                };
            });
            // Never let a single media preload failure block rendering of the round.
            // Other callers invoke this without `.catch()`.
            return preloader.catch(function () {
                return {
                    ready: false,
                    audioReady: !needsAudio,
                    imageReady: !shouldPreloadImage,
                    wordId: parseInt(word.id, 10) || word.id
                };
            });
        }

        function resolvePlayableAudio(word) {
            if (!word || typeof word !== 'object') return '';

            const trimString = (value) => (typeof value === 'string') ? value.trim() : '';
            const sanitize = (value) => {
                const trimmed = trimString(value);
                if (!trimmed) return '';
                const lowered = trimmed.toLowerCase();
                if (lowered === 'null' || lowered === 'undefined' || lowered === '#') {
                    return '';
                }
                return trimmed;
            };

            const primaryAudio = sanitize(word.audio);
            if (primaryAudio) {
                word.audio = primaryAudio;
                return primaryAudio;
            }

            let selectedAudio = '';

            try {
                if (window.FlashcardAudio && typeof window.FlashcardAudio.selectBestAudio === 'function') {
                    const preferredOrder = ['question', 'isolation', 'introduction', 'in sentence'];
                    selectedAudio = sanitize(window.FlashcardAudio.selectBestAudio(word, preferredOrder));
                }
            } catch (err) {
                console.warn('FlashcardLoader: Failed to select best audio', err);
            }

            if (!selectedAudio && Array.isArray(word.audio_files)) {
                const fallback = word.audio_files.find(file => file && sanitize(file.url));
                if (fallback) {
                    selectedAudio = sanitize(fallback.url);
                }
            }

            if (selectedAudio) {
                word.audio = selectedAudio;
                return selectedAudio;
            }

            return '';
        }

        return {
            loadedCategories,
            loadedResources,
            loadAudio,
            loadImage,
            loadResourcesForCategory,
            preloadCategoryResources,
            preloadNextCategories,
            loadResourcesForWord,
            processFetchedWordData,
            randomlySort,
            resetCacheForNewWordset,
        };
    })();

    // Expose FlashcardLoader globally
    window.FlashcardLoader = FlashcardLoader;

})(jQuery);
