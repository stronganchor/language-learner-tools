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

        // Load resources for a single word (audio or image)
        // Called selectively in flashcard-script.js for the
        // target word and its quiz options.
        function loadResourcesForWord(word, displayMode = 'image') {
            FlashcardAudio.loadAudio(word.audio);
            if (displayMode === 'image') {
                loadImage(word.image);
            }
        }

        // Load an image so that it's cached for later use
        function loadImage(imageURL) {
            if (!loadedResources[imageURL] && imageURL) {
                new Promise((resolve, reject) => {
                    let img = new Image();
                    img.onload = function() {
                        resolve(img);
                    };
                    img.onerror = function() {
                        reject(new Error('Image load failed'));
                    };
                    img.src = imageURL;
                });
                loadedResources[imageURL] = true;
            }
        }

        // Store the fetched word data in memory (window.wordsByCategory),
        // but do not load every resource at once.
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

        // Fetch the word list for a category (via AJAX) and then do a partial resource preload.
        function loadResourcesForCategory(categoryName, callback) {
            // If this category is already loaded
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

        // Preload the next few categories by fetching their word lists.
        // The actual resource loading for each category will happen in chunks
        // once loadResourcesForCategory() completes.
        function preloadNextCategories() {
            var numberToPreload = 3;

            // For all category names
            window.categoryNames.forEach(function(categoryName) {
                // If the category is not loaded
                if (!loadedCategories.includes(categoryName)) {
                    loadResourcesForCategory(categoryName);
                    numberToPreload--;
                    if (numberToPreload === 0) {
                        return;
                    }
                }
            });
        }

        // Preload resources in chunks if the category is large;
        // otherwise load them all in one go.
        function preloadCategoryResources(categoryName, onComplete) {
            const words = window.wordsByCategory[categoryName] || [];
            const totalWords = words.length;
            if (totalWords === 0) {
                if (typeof onComplete === 'function') {
                    onComplete();
                }
                return;
            }

            // If fewer than or equal to 30 words, load them all at once.
            // If more than 30, load in small chunks (e.g., 10 at a time).
            const chunkSize = (totalWords <= 30) ? totalWords : 10;
            let currentIndex = 0;
            let displayMode = window.getCategoryDisplayMode(categoryName);

            function loadChunk() {
                if (currentIndex >= totalWords) {
                    if (typeof onComplete === 'function') {
                        onComplete();
                    }
                    return;
                }

                let end = Math.min(currentIndex + chunkSize, totalWords);
                let chunk = words.slice(currentIndex, end);

                let loadPromises = chunk.map((word) => {
                    return new Promise((resolve) => {
                        FlashcardAudio.loadAudio(word.audio);
                        if (displayMode === 'image' && word.image && !loadedResources[word.image]) {
                            let img = new Image();
                            img.onload = function() {
                                loadedResources[word.image] = true;
                                resolve();
                            };
                            img.onerror = function() {
                                resolve();
                            };
                            img.src = word.image;
                        } else {
                            resolve();
                        }
                    });
                });

                Promise.all(loadPromises).then(() => {
                    currentIndex = end;
                    loadChunk();
                });
            }

            loadChunk();
        }

        // Expose functions and variables as needed
        return {
            loadedCategories: loadedCategories,
            loadedResources: loadedResources,
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
