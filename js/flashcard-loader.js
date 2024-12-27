(function($) {
    // FlashcardLoader Module
    const FlashcardLoader = (function() {
        // Variables related to resource loading
        const loadedCategories = []; // Categories that have been loaded
        const loadedResources = {};

        // Helper function to randomly sort an array
        function randomlySort(inputArray) {
            if (!Array.isArray(inputArray)) {
                return inputArray;
            }
            return [...inputArray].sort(() => 0.5 - Math.random());
        }

        /**
         * Create an ephemeral Audio() object and load a given URL.
         * Once "canplaythrough" or "error" fires, we remove the source to allow garbage-collection.
         */
        function loadAudio(audioURL) {
            // If already loaded, skip
            if (!audioURL || loadedResources[audioURL]) {
                return Promise.resolve();
            }

            return new Promise((resolve) => {
                let audio = new Audio();
                audio.src = audioURL;

                // Mark as loaded on success
                audio.oncanplaythrough = function() {
                    loadedResources[audioURL] = true;
                    cleanupAudio(audio);
                    resolve();
                };

                // Even on error, mark as loaded so we don’t retry indefinitely
                audio.onerror = function() {
                    loadedResources[audioURL] = true;
                    cleanupAudio(audio);
                    resolve();
                };
            });
        }

        /**
         * Clean up an Audio() element so it doesn’t remain in memory.
         */
        function cleanupAudio(audio) {
            if (!audio) return;
            audio.pause();
            audio.removeAttribute('src');
            audio.load(); 
            // The audio var will go out of scope in the calling function, so it can be GC’d.
        }

        /**
         * Load an image (no persistent DOM element). 
         * This doesn't trigger the same WebMediaPlayer cap as audio does.
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
         *  Called selectively in flashcard-script.js for the target word and its quiz options. 
         *  Uses ephemeral loading for audio so we don’t build up a large pool of audio elements.
         */
        function loadResourcesForWord(word, displayMode = 'image') {
            if (!word) return;
            // Fire off an ephemeral load for the audio
            loadAudio(word.audio);
            // And an image load if needed
            if (displayMode === 'image') {
                loadImage(word.image);
            }
        }

        /**
         *  Store the fetched word data in memory without mass-creating audio elements. 
         *  We'll do partial preloading separately (preloadCategoryResources).
         */
        function processFetchedWordData(wordData, categoryName, displayMode) {
            if (!window.wordsByCategory[categoryName]) {
                window.wordsByCategory[categoryName] = [];
            }
            if (!window.categoryRoundCount[categoryName]) {
                window.categoryRoundCount[categoryName] = 0;
            }

            wordData.forEach(function(word) {
                window.wordsByCategory[categoryName].push(word);
            });

            // Randomize the order of the words in this category
            window.wordsByCategory[categoryName] = randomlySort(window.wordsByCategory[categoryName]);

            // Add the category to the list of loaded categories
            loadedCategories.push(categoryName);
        }

        /**
         *  Fetch the word list for a category (via AJAX).
         *  Then do partial resource preloading in chunks (preloadCategoryResources).
         */
        function loadResourcesForCategory(categoryName, callback) {
            // If this category is already loaded, skip
            if (loadedCategories.includes(categoryName)) {
                if (typeof callback === 'function') {
                    callback();
                }
                return;
            }

            let displayMode = window.getCategoryDisplayMode(categoryName);

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

                        // After storing the words, begin partial preloading
                        preloadCategoryResources(categoryName, function() {
                            if (typeof callback === 'function') {
                                callback();
                            }
                        });
                    } else {
                        console.error('Failed to load words for category:', categoryName);
                        if (typeof callback === 'function') {
                            callback();
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX request failed for category:', categoryName, 'Error:', error);
                    if (typeof callback === 'function') {
                        callback();
                    }
                }
            });
        }

        /**
         *  Preload the next few categories (just their word lists) by calling loadResourcesForCategory.
         *  Each category’s actual resources (audio/image) will be chunk-preloaded in preloadCategoryResources.
         */
        function preloadNextCategories() {
            var numberToPreload = 3;

            // For all category names
            window.categoryNames.forEach(function(categoryName) {
                // If the category is not yet loaded
                if (!loadedCategories.includes(categoryName)) {
                    loadResourcesForCategory(categoryName);
                    numberToPreload--;
                    if (numberToPreload === 0) {
                        return;
                    }
                }
            });
        }

        /**
         *  Once a category’s words are fetched, we do partial preloads of the audio/image data 
         *  in small chunks (10 at a time if more than 30 total words).
         */
        function preloadCategoryResources(categoryName, onComplete) {
            const words = window.wordsByCategory[categoryName] || [];
            const totalWords = words.length;
            if (totalWords === 0) {
                if (typeof onComplete === 'function') {
                    onComplete();
                }
                return;
            }

            // If 30 or fewer words, load them all at once; otherwise in chunks of 10.
            const chunkSize = (totalWords <= 30) ? totalWords : 10;
            let currentIndex = 0;
            let displayMode = window.getCategoryDisplayMode(categoryName);

            function loadNextChunk() {
                if (currentIndex >= totalWords) {
                    // Done preloading the entire category
                    if (typeof onComplete === 'function') {
                        onComplete();
                    }
                    return;
                }

                let end = Math.min(currentIndex + chunkSize, totalWords);
                let chunk = words.slice(currentIndex, end);

                // Start ephemeral loads for each word in the chunk
                let loadPromises = chunk.map((word) => {
                    return Promise.all([
                        loadAudio(word.audio),
                        (displayMode === 'image' ? loadImage(word.image) : Promise.resolve())
                    ]);
                });

                // Once all items in this chunk are loaded (or errored), move to the next chunk
                Promise.all(loadPromises).then(() => {
                    currentIndex = end;
                    loadNextChunk();
                });
            }

            // Start the chain
            loadNextChunk();
        }

        // Expose functions and variables as needed
        return {
            loadedCategories: loadedCategories,
            loadedResources: loadedResources,
            loadAudio: loadAudio,
            loadResourcesForWord: loadResourcesForWord,
            loadImage: loadImage,
            processFetchedWordData: processFetchedWordData,
            loadResourcesForCategory: loadResourcesForCategory,
            preloadNextCategories: preloadNextCategories,
        };
    })();

    // Expose FlashcardLoader globally
    window.FlashcardLoader = FlashcardLoader;

})(jQuery);
