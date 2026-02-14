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
        let lastWordsetKey = null;
        const defaultConfig = { prompt_type: 'audio', option_type: 'image' };
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

        /**
         * Loads an audio file and marks it as loaded.
         *
         * @param {string} audioURL - The URL of the audio file.
         * @returns {Promise} Resolves when the audio is loaded or fails.
         */
        function loadAudio(audioURL) {
            if (!audioURL || loadedResources[audioURL]) {
                return Promise.resolve();
            }

            return new Promise((resolve) => {
                let audio = document.createElement('audio');
                let settled = false;
                let timeoutId = null;
                const settle = () => {
                    if (settled) { return; }
                    settled = true;
                    loadedResources[audioURL] = true;
                    if (timeoutId) {
                        clearTimeout(timeoutId);
                        timeoutId = null;
                    }
                    try { audio.oncanplaythrough = null; } catch (_) { /* no-op */ }
                    try { audio.onerror = null; } catch (_) { /* no-op */ }
                    try { audio.removeEventListener('loadstart', settle); } catch (_) { /* no-op */ }
                    try { audio.removeEventListener('loadeddata', settle); } catch (_) { /* no-op */ }
                    try { audio.removeEventListener('stalled', settle); } catch (_) { /* no-op */ }
                    try { audio.removeEventListener('abort', settle); } catch (_) { /* no-op */ }
                    cleanupAudio(audio);
                    audio = null;
                    resolve();
                };

                audio.crossOrigin = 'anonymous';
                audio.preload = 'auto';

                // Register listeners before setting src/load to avoid missing early events.
                audio.oncanplaythrough = settle;
                audio.onerror = settle;
                audio.addEventListener('loadstart', settle, { once: true });
                audio.addEventListener('loadeddata', settle, { once: true });
                audio.addEventListener('stalled', settle, { once: true });
                audio.addEventListener('abort', settle, { once: true });

                // Safety net so one bad media request can never stall quiz boot.
                timeoutId = setTimeout(settle, 4000);

                try {
                    audio.src = audioURL;
                    audio.load();
                } catch (_) {
                    settle();
                }
            });
        }

        /**
         * Cleans up an Audio object to free memory.
         *
         * @param {HTMLAudioElement} audio - The audio element to clean up.
         */
        function cleanupAudio(audio) {
            if (!audio) return;
            audio.pause();
            audio.removeAttribute('src');
            audio.load();
            // If for any reason it was added to the DOM, remove it.
            if (audio.parentNode) {
                audio.parentNode.removeChild(audio);
            }
        }

        /**
         * Loads an image file and marks it as loaded.
         *
         * @param {string} imageURL - The URL of the image file.
         * @returns {Promise} Resolves when the image is loaded or fails.
         */
        function loadImage(imageURL) {
            if (!imageURL || loadedResources[imageURL]) {
                return Promise.resolve();
            }
            return new Promise((resolve) => {
                let img = new Image();
                let settled = false;
                let timeoutId = null;
                const settle = () => {
                    if (settled) { return; }
                    settled = true;
                    loadedResources[imageURL] = true;
                    if (timeoutId) {
                        clearTimeout(timeoutId);
                        timeoutId = null;
                    }
                    img.onload = null;
                    img.onerror = null;
                    resolve();
                };

                img.onload = settle;
                img.onerror = settle;
                timeoutId = setTimeout(settle, 4000);

                try {
                    img.src = imageURL;
                } catch (_) {
                    settle();
                }
            });
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
                if (needsAudio && !w.audio) return false;
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

            loadedCategories.push(categoryName);
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
            const wordsetKey = ensureWordsetCacheKey();
            const cacheKey = wordsetKey + '::' + categoryName;

            if (loadedCategories.includes(cacheKey)) {
                if (typeof callback === 'function') callback();
                return;
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
            try {
                window.__LL_LAST_WORDS_AJAX = {
                    startedAt: Date.now(),
                    category: categoryName,
                    url: (window.llToolsFlashcardsData && window.llToolsFlashcardsData.ajaxurl) ? window.llToolsFlashcardsData.ajaxurl : '',
                    page: window.location && window.location.href ? window.location.href : '',
                    payload: Object.assign({}, payload)
                };
            } catch (_) {}

            $.ajax({
                url: llToolsFlashcardsData.ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: payload,
                success: function (response) {
                    try {
                        if (window.__LL_LAST_WORDS_AJAX) {
                            window.__LL_LAST_WORDS_AJAX.endedAt = Date.now();
                            window.__LL_LAST_WORDS_AJAX.response = response;
                        }
                    } catch (_) {}
                    if (response.success) {
                        processFetchedWordData(response.data, categoryName);
                        if (earlyCallback && typeof callback === 'function') {
                            // Let the caller continue as soon as data is available; preload continues in background.
                            try { callback(); } catch (_) { }
                            preloadCategoryResources(categoryName);
                        } else {
                            preloadCategoryResources(categoryName, callback);
                        }
                        loadedCategories.push(cacheKey);
                    } else {
                        console.error('Failed to load words for category:', categoryName, response);
                        if (typeof callback === 'function') callback();
                    }
                },
                error: function (xhr, status, error) {
                    try {
                        if (window.__LL_LAST_WORDS_AJAX) {
                            window.__LL_LAST_WORDS_AJAX.endedAt = Date.now();
                            window.__LL_LAST_WORDS_AJAX.error = {
                                status: status,
                                error: error,
                                httpStatus: xhr && typeof xhr.status !== 'undefined' ? xhr.status : null,
                                responseText: xhr && typeof xhr.responseText === 'string' ? xhr.responseText : ''
                            };
                        }
                    } catch (_) {}
                    console.error('AJAX request failed for category:', categoryName, {
                        status: status,
                        error: error,
                        httpStatus: xhr && typeof xhr.status !== 'undefined' ? xhr.status : null,
                        responseText: xhr && typeof xhr.responseText === 'string' ? xhr.responseText : ''
                    });
                    if (typeof callback === 'function') callback();
                }
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

            const chunkSize = (totalWords <= 30) ? totalWords : 20;
            let currentIndex = 0;
            const displayMode = window.getCategoryDisplayMode(categoryName);
            const cfg = getCategoryConfig(categoryName);
            const needsAudio = categoryRequiresAudio(cfg);
            const needsImage = categoryRequiresImage(cfg, displayMode);

            let hasTriggeredFirstChunkLoad = false; // Ensure we only fire onFirstChunkLoaded once

            function loadNextChunk() {
                if (currentIndex >= totalWords) {
                    // All chunks loaded, we're done.
                    loadedCategories.push(categoryName);
                    return;
                }

                // Get the slice of words for this chunk
                const end = Math.min(currentIndex + chunkSize, totalWords);
                const chunk = words.slice(currentIndex, end);

                // Preload images/audio in this chunk
                const loadPromises = chunk.map((word) => {
                    return Promise.all([
                        (needsAudio && word.audio ? loadAudio(word.audio) : Promise.resolve()),
                        (needsImage && word.image ? loadImage(word.image) : Promise.resolve())
                    ]);
                });

                // Once all items in the chunk are loaded (or failed), move to the next chunk
                Promise.all(loadPromises).then(() => {
                    currentIndex = end;

                    // After loading the first chunk, proceed with the callback function
                    if (!hasTriggeredFirstChunkLoad) {
                        hasTriggeredFirstChunkLoad = true;
                        if (typeof onFirstChunkLoaded === 'function') {
                            onFirstChunkLoaded();
                        }

                    }

                    // Continue loading subsequent chunks in the background
                    loadNextChunk();
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
         * @returns {Promise} Resolves when resources are loaded.
         */
        function loadResourcesForWord(word, displayMode, categoryName, categoryConfig) {
            if (!word) return Promise.resolve();
            const cfg = categoryConfig || getCategoryConfig(categoryName || (window.LLFlashcards && window.LLFlashcards.State && window.LLFlashcards.State.currentCategoryName) || '');
            const needsAudio = categoryRequiresAudio(cfg);
            const needsImage = categoryRequiresImage(cfg, displayMode);
            const preloader = Promise.all([
                (needsAudio && word.audio ? loadAudio(word.audio) : Promise.resolve()),
                (needsImage && word.image ? loadImage(word.image) : Promise.resolve())
            ]);
            // Never let a single media preload failure block rendering of the round.
            // Other callers invoke this without `.catch()`.
            return preloader.catch(function () { return []; });
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
