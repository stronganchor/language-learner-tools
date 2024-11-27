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

        // Load resources for a word
        function loadResourcesForWord(word) {
            FlashcardAudio.loadAudio(word.audio);
            if (llToolsFlashcardsData.displayMode === 'image') {
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

        function processFetchedWordData(wordData, categoryName) {
            if (!window.wordsByCategory[categoryName]) {
                window.wordsByCategory[categoryName] = [];
            }
            if (!window.categoryRoundCount[categoryName]) {
                window.categoryRoundCount[categoryName] = 0;
            }

            // For each loaded word
            wordData.forEach(function(word) {
                window.wordsByCategory[categoryName].push(word);
                loadResourcesForWord(word);
            });

            // Randomize the order of the words in this category
            window.wordsByCategory[categoryName] = randomlySort(window.wordsByCategory[categoryName]);

            // Add the category to the list of loaded categories
            loadedCategories.push(categoryName);
        }

        // Load resources for a category
        function loadResourcesForCategory(categoryName, callback) {
            // If this category is already loaded
            if (loadedCategories.includes(categoryName)) {
                if (typeof callback === 'function') {
                    callback();
                }
                return;
            }

            $.ajax({
                url: llToolsFlashcardsData.ajaxurl,
                method: 'POST',
                data: {
                    action: 'll_get_words_by_category',
                    category: categoryName,
                    display_mode: llToolsFlashcardsData.displayMode
                },
                success: function(response) {
                    if (response.success) {
                        // Process the received words data
                        processFetchedWordData(response.data, categoryName);

                        if (typeof callback === 'function') {
                            callback();
                        }
                    } else {
                        console.error('Failed to load words for category:', categoryName);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX request failed for category:', categoryName, 'Error:', error);
                    // TODO: Handle the error gracefully, e.g., show an error message to the user
                }
            });
        }

        // Preload the next few categories to avoid delays when switching categories
        function preloadNextCategories() {
            var numberToPreload = 3;

            // For all category names
            window.categoryNames.forEach(function(categoryName) {
                // If the category is not loaded
                if (!loadedCategories.includes(categoryName)) {
                    // Load the resources for the category
                    loadResourcesForCategory(categoryName);
                    numberToPreload--;
                    if (numberToPreload === 0) {
                        return;
                    }
                }
            });
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
