/**
 * flashcard-loader.js
 *
 * Handles loading of resources (audio, images) for flashcards.
 */
(function($) {
    /**
     * FlashcardLoader Module
     * 
     * Manages the loading of audio and image resources for flashcards, ensuring efficient preloading and organization by category.
     */
    const FlashcardLoader = (function() {
        // Tracks loaded categories and resources
        const loadedCategories = [];
        const loadedResources = {};

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
                let audio = new Audio(audioURL);

                const onLoad = () => {
                    loadedResources[audioURL] = true;
                    cleanupAudio(audio);
                    resolve();
                };

                audio.oncanplaythrough = onLoad;
                audio.onerror = onLoad;
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
            audio.src = '';
            audio.load();
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

                img.onload = () => {
                    loadedResources[imageURL] = true;
                    resolve();
                };

                img.onerror = () => {
                    loadedResources[imageURL] = true;
                    resolve();
                };

                img.src = imageURL;
            });
        }

        /**
         * Processes fetched word data and organizes it by category.
         *
         * @param {Array} wordData - Array of word objects.
         * @param {string} categoryName - The name of the category.
         * @param {string} displayMode - The display mode ('image' or 'text').
         */
        function processFetchedWordData(wordData, categoryName, displayMode) {
            if (!window.wordsByCategory[categoryName]) {
                window.wordsByCategory[categoryName] = [];
            }
            if (!window.categoryRoundCount[categoryName]) {
                window.categoryRoundCount[categoryName] = 0;
            }

            window.wordsByCategory[categoryName].push(...wordData);
            window.wordsByCategory[categoryName] = randomlySort(window.wordsByCategory[categoryName]);

            loadedCategories.push(categoryName);
        }

        /**
         * Loads resources for a specific category via AJAX.
         *
         * @param {string} categoryName - The name of the category.
         * @param {function} callback - Callback to execute after loading.
         */
        function loadResourcesForCategory(categoryName, callback) {
            if (loadedCategories.includes(categoryName)) {
                if (typeof callback === 'function') callback();
                return;
            }

            const displayMode = window.getCategoryDisplayMode(categoryName);

            $.ajax({
                url: llToolsFlashcardsData.ajaxurl,
                method: 'POST',
                data: {
                    action: 'll_get_words_by_category',
                    category: categoryName,
                    display_mode: displayMode
                },
                success: function(response) {
                    if (response.success) {
                        processFetchedWordData(response.data, categoryName, displayMode);
                        preloadCategoryResources(categoryName, callback);
                    } else {
                        console.error('Failed to load words for category:', categoryName);
                        if (typeof callback === 'function') callback();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX request failed for category:', categoryName, 'Error:', error);
                    if (typeof callback === 'function') callback();
                }
            });
        }

        /**
         * Preloads resources for a category in chunks to optimize performance.
         *
         * @param {string} categoryName - The name of the category.
         * @param {function} onComplete - Callback after preloading.
         */
        function preloadCategoryResources(categoryName, onComplete) {
            const words = window.wordsByCategory[categoryName] || [];
            const totalWords = words.length;
            if (totalWords === 0) {
                if (typeof onComplete === 'function') onComplete();
                return;
            }

            const chunkSize = (totalWords <= 30) ? totalWords : 10;
            let currentIndex = 0;
            const displayMode = window.getCategoryDisplayMode(categoryName);

            function loadNextChunk() {
                if (currentIndex >= totalWords) {
                    if (typeof onComplete === 'function') onComplete();
                    return;
                }

                const end = Math.min(currentIndex + chunkSize, totalWords);
                const chunk = words.slice(currentIndex, end);

                const loadPromises = chunk.map((word) => {
                    return Promise.all([
                        loadAudio(word.audio),
                        (displayMode === 'image' ? loadImage(word.image) : Promise.resolve())
                    ]);
                });

                Promise.all(loadPromises).then(() => {
                    currentIndex = end;
                    loadNextChunk();
                });
            }

            loadNextChunk();
        }

        /**
         * Preloads the next set of categories.
         *
         * @param {number} numberToPreload - Number of categories to preload.
         */
        function preloadNextCategories(numberToPreload = 3) {
            window.categoryNames.forEach(function(categoryName) {
                if (!loadedCategories.includes(categoryName) && numberToPreload > 0) {
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
        function loadResourcesForWord(word, displayMode) {
            if (!word) return Promise.resolve();
            return Promise.all([
                loadAudio(word.audio),
                (displayMode === 'image' ? loadImage(word.image) : Promise.resolve())
            ]);
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
        };
    })();

    // Expose FlashcardLoader globally
    window.FlashcardLoader = FlashcardLoader;

})(jQuery);
