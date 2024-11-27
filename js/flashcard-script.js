(function($) {
    const ROUNDS_PER_CATEGORY = 6;
    const MINIMUM_NUMBER_OF_OPTIONS = 2;
    const MAXIMUM_NUMBER_OF_OPTIONS = 9;
    const DEFAULT_NUMBER_OF_OPTIONS = 2;
    const MAXIMUM_TEXT_OPTIONS = 4; // Limit text-based quizzes to 4 options per round
    const MAX_ROWS = 3;
    const LANDSCAPE_CARD_WIDTH = 200;
    const PORTRAIT_CARD_HEIGHT = 200;
    const DEFAULT_CARD_PIXELS = 150;

	// Attach shared variables to window
    window.categoryNames = []; // All the category names
    window.wordsByCategory = {}; // Maps category names to arrays of word objects
    window.categoryRoundCount = {}; // Tracks rounds per category
    window.firstCategoryName = llToolsFlashcardsData.firstCategoryName;

    // Variables related to the quiz state
    var defaultNumberOfOptions = DEFAULT_NUMBER_OF_OPTIONS;
    var usedWordIDs = []; // Set of IDs of words we've covered so far
    var wrongIndexes = []; // Tracks indexes of wrong answers this turn
    var currentCategory = null;
    var currentCategoryName = null;
    var currentCategoryRoundCount = 0;
    var isFirstRound = true;
    var categoryOptionsCount = {}; // Tracks the number of options for each category
    var categoryRepetitionQueues = {}; // Manages separate repetition queues for each category
    var userClickedSkip = false;
    var maxCardWidth = DEFAULT_CARD_PIXELS;
    var maxCardHeight = DEFAULT_CARD_PIXELS;
    let quizResults = {
        correctOnFirstTry: 0,
        incorrect: [],
        skipped: 0
    };

    // Resets variables related to the quiz state when closing/restarting the quiz
    function resetQuizState() {
        usedWordIDs = [];
        categoryRoundCount = {};
        wrongIndexes = [];
        currentCategory = null;
        currentCategoryName = null;
        currentCategoryRoundCount = 0;
        isFirstRound = true;
        categoryOptionsCount = {};
        categoryRepetitionQueues = {};
        userClickedSkip = false;
        resetQuizResults();
        FlashcardAudio.resetAudioState();
        maxCardWidth = DEFAULT_CARD_PIXELS;
        maxCardHeight = DEFAULT_CARD_PIXELS;
    }

    function resetQuizResults() {
        quizResults = {
            correctOnFirstTry: 0,
            incorrect: [],
            skipped: 0
        };
    }

    // Preload the first category
    if (llToolsFlashcardsData.categoriesPreselected) {
        processFetchedWordData(llToolsFlashcardsData.firstCategoryData, firstCategoryName);
    }

    // Initialize the audio module
    FlashcardAudio.initializeAudio();

    // Helper function to randomly sort an array
    function randomlySort(inputArray) {
        if (!Array.isArray(inputArray)) {
            return inputArray;
        }
        return [...inputArray].sort(() => 0.5 - Math.random());
    }

    // Returns a random integer between the min and max values (inclusive)
    function randomIntFromInterval(min, max) {
        return Math.floor((Math.random() * (max - min + 1)) + min)
    }

    function canAddMoreCards() {
        const cards = $('.flashcard-container');

        // If there are fewer than the minimum number of options, always try to add more
        if (cards.length < MINIMUM_NUMBER_OF_OPTIONS) {
            return true;
        }

        const container = $('#ll-tools-flashcard');
        const containerWidth = container.width();
        const containerHeight = container.height();

        const lastCard = cards.last();
        const cardWidth = maxCardWidth;
        const cardHeight = maxCardHeight;

        // Get the computed style of the container to extract the gap value
        const containerStyle = window.getComputedStyle(container[0]);
        const gapValue = parseInt(containerStyle.getPropertyValue('gap'), 10);

        // Calculate the number of cards per row considering the gap
        const cardsPerRow = Math.floor((containerWidth + gapValue) / (cardWidth + gapValue));

        const rows = Math.ceil(cards.length / cardsPerRow);

        if (rows > MAX_ROWS) {
            return false;
        }

        const cardsInLastRow = cards.length - (cardsPerRow * (rows - 1));
        const lastRowWidth = cardsInLastRow * (cardWidth + gapValue) - gapValue;
        const remainingWidth = containerWidth - lastRowWidth;

        // Calculate the remaining height considering the gap
        const remainingHeight = containerHeight - (lastCard.offset().top + cardHeight - container.offset().top + (rows - 1) * gapValue);

        const thisIsTheLastRow = (rows === MAX_ROWS) || (remainingHeight < cardHeight);

        // If there is not enough space for another card, return false
        if (thisIsTheLastRow && remainingWidth < (cardWidth + gapValue)) {
            return false;
        }

        return true;
    }

    // Check a value against the min and max constraints and return the value or the min/max if out of bounds
    function checkMinMax(optionsCount, categoryName) {
        let maxOptionsCount = MAXIMUM_NUMBER_OF_OPTIONS;
        if (llToolsFlashcardsData.displayMode === "text") {
            maxOptionsCount = MAXIMUM_TEXT_OPTIONS;
        }
        if (wordsByCategory[categoryName]) {
            // Limit the number of options to the total number of words in this category, or the maximum number
            maxOptionsCount = Math.min(maxOptionsCount, wordsByCategory[categoryName].length);
        }
        maxOptionsCount = Math.max(MINIMUM_NUMBER_OF_OPTIONS, maxOptionsCount);
        return Math.min(Math.max(MINIMUM_NUMBER_OF_OPTIONS, optionsCount), maxOptionsCount)
    }

    // Set up initial values for the number of options to display for each category of words
    function initializeOptionsCount(number_of_options) {
        if (number_of_options) {
            defaultNumberOfOptions = number_of_options;
        }

        categoryNames.forEach(categoryName => {
            setInitialOptionsCount(categoryName);
        });
    }

    // Limit the number of options based on the number of words in that category as well as the min/max constraints
    function setInitialOptionsCount(categoryName) {
        let existingCount = categoryOptionsCount[categoryName];
        if (existingCount && existingCount === checkMinMax(existingCount, categoryName)) {
            return;
        }

        if (wordsByCategory[categoryName]) {
            categoryOptionsCount[categoryName] = checkMinMax(Math.min(wordsByCategory[categoryName].length, defaultNumberOfOptions), categoryName);
        } else {
            // Handle any mismatches between categoryNames and wordsByCategory arrays
            categoryOptionsCount[categoryName] = checkMinMax(defaultNumberOfOptions, categoryName);
        }
    }

    // Calculate the number of options in a quiz dynamically based on user answers
    function calculateNumberOfOptions() {
        let number_of_options = categoryOptionsCount[currentCategoryName];
        number_of_options = checkMinMax(number_of_options, currentCategoryName);
        if (wrongIndexes.length > 0) {
            // Show fewer options if the user got it wrong last round
            number_of_options--;
        } else if (!isFirstRound) {
            // Add in more options if the user got the last card right on the first try
            number_of_options++;
        }

        wrongIndexes = [];

        // Update the count for the current category
        categoryOptionsCount[currentCategoryName] = checkMinMax(number_of_options, currentCategoryName);
    }

    function selectTargetWord(candidateCategory, candidateCategoryName) {
        if (!candidateCategory) {
            return null;
        }

        let target = null;
        // Check if there are words due to reappear from the repetition queue
        const repeatQueue = categoryRepetitionQueues[candidateCategoryName];
        if (repeatQueue && repeatQueue.length > 0) {
            for (let i = 0; i < repeatQueue.length; i++) {
                if (repeatQueue[i].reappearRound <= categoryRoundCount[candidateCategoryName]) {
                    target = repeatQueue[i].wordData;
                    // Remove the word from the queue as it's now selected to reappear
                    repeatQueue.splice(i, 1);
                    break; // Exit the loop once the target word is selected
                }
            }
        }

        // If we didn't select a word from the repeat queue, attempt to select from the chosen category
        if (!target) {
            for (let i = 0; i < candidateCategory.length; i++) {
                if (!usedWordIDs.includes(candidateCategory[i].id)) { // Ensure this word hasn't been recently used as a target
                    target = candidateCategory[i];
                    usedWordIDs.push(target.id); // Add to usedWordIDs as it's now the target
                    break; // Exit the loop once the target word is selected
                }
            }
        }

        // If we still didn't select a word, but there are repeatQueue words that aren't ready yet
        if (!target && repeatQueue && repeatQueue.length > 0) {
            target = repeatQueue[0].wordData;

            // Check if the target word is the same as the previous one
            if (target.id === usedWordIDs[usedWordIDs.length - 1]) {
                // If it's the same and there are other words in the category, try to select a different one
                const otherWords = currentCategory.filter(word => word.id !== target.id);
                if (otherWords.length > 0) {
                    target = otherWords[Math.floor(Math.random() * otherWords.length)];
                }
            }

            // Remove the target word from the repeat queue
            const targetWordIndex = repeatQueue.findIndex(item => item.wordData.id === target.id);
            if (targetWordIndex !== -1) {
                repeatQueue.splice(targetWordIndex, 1);
            }
        }

        if (target) {
            if (currentCategoryName !== candidateCategoryName) {
                currentCategoryName = candidateCategoryName;
                currentCategoryRoundCount = 0;
                FlashcardLoader.preloadNextCategories();
            }
            currentCategory = wordsByCategory[candidateCategoryName];
            categoryRoundCount[candidateCategoryName]++;
            currentCategoryRoundCount++;
        }

        return target;
    }

    function selectWordFromNextCategory() {
        for (let categoryName of categoryNames) {
            let targetWord = selectTargetWord(wordsByCategory[categoryName], categoryName);
            if (targetWord) {
                return targetWord; // Exit the loop if we have found a target word
            }
        }
        return null;
    }

    function selectTargetWordAndCategory() {
        let targetWord = null;

        if (isFirstRound) {
            if (!firstCategoryName) {
                firstCategoryName = categoryNames[Math.floor(Math.random() * categoryNames.length)];
            }
            targetWord = selectTargetWord(wordsByCategory[firstCategoryName], firstCategoryName);
            currentCategoryName = firstCategoryName;
            currentCategory = wordsByCategory[currentCategoryName];
        } else {
            const repeatQueue = categoryRepetitionQueues[currentCategoryName];
            if ((repeatQueue && repeatQueue.length > 0) || currentCategoryRoundCount <= ROUNDS_PER_CATEGORY) {
                targetWord = selectTargetWord(currentCategory, currentCategoryName);
            } else {
                // Put the current category at the end of the category names list
                categoryNames.splice(categoryNames.indexOf(currentCategoryName), 1);
                categoryNames.push(currentCategoryName);

                // Reset the round count for this category so we can come back to it again later
                categoryRoundCount[currentCategoryName] = 0;
                currentCategoryRoundCount = 0;
            }
        }

        if (!targetWord) {
            targetWord = selectWordFromNextCategory();
        }     

        return targetWord;
    }

    function selectWordsFromCategory(category, selectedWords) {
        for (let candidateWord of wordsByCategory[category]) {
            // Check if the candidate word is not already selected and not similar to any selected words
            let isDuplicate = selectedWords.some(word => word.id === candidateWord.id);
            let isSimilar = selectedWords.some(word => word.similar_word_id === candidateWord.id.toString() || candidateWord.similar_word_id === word.id.toString());
            let hasSameImage = llToolsFlashcardsData.displayMode === 'image' && selectedWords.some(word => word.image === candidateWord.image);

            // If this word passes the checks, add it to the selected words and container
            if (!isDuplicate && !isSimilar && !hasSameImage) {
                selectedWords.push(candidateWord);
                FlashcardLoader.loadResourcesForWord(candidateWord);
                appendWordToContainer(candidateWord);

                if (selectedWords.length >= categoryOptionsCount[currentCategoryName] || !canAddMoreCards()) {
                    break;
                }
            }
        }
        return selectedWords;
    }

    function fillQuizOptions(targetWord) {
        let selectedWords = [];

        let categoryNamesToTry = [];

        // The first category to try will be the current category
        categoryNamesToTry.push(currentCategoryName);

        // If we don't find enough words in the current category, look at this word's other categories
        if (targetWord.all_categories) {
            for (let category of targetWord.all_categories) {
                if (!categoryNamesToTry.includes(category)) {
                    categoryNamesToTry.push(category);
                }
            }
        }

        // If that still isn't enough, look at all other categories
        categoryNames.forEach(category => {
            if (!categoryNamesToTry.includes(category)) {
                categoryNamesToTry.push(category);
            }
        });

        FlashcardLoader.loadResourcesForWord(targetWord);
        selectedWords.push(targetWord);
        appendWordToContainer(targetWord);

        while (selectedWords.length < categoryOptionsCount[currentCategoryName] && canAddMoreCards()) {
            if (categoryNamesToTry.length === 0 || selectedWords.length >= categoryOptionsCount[currentCategoryName]) break;
            let candidateCategoryName = categoryNamesToTry.shift();
            if (!wordsByCategory[candidateCategoryName] || FlashcardLoader.loadedCategories.includes(candidateCategoryName)) {
                // If the words data is not loaded, request it and continue with the next category
                FlashcardLoader.loadResourcesForCategory(candidateCategoryName);
            }

            wordsByCategory[candidateCategoryName] = randomlySort(wordsByCategory[candidateCategoryName]);
            selectedWords = selectWordsFromCategory(candidateCategoryName, selectedWords);
        }

        // Add click events to the cards
        $('.flashcard-container').each(function (index) {
            addClickEventToCard($(this), index, targetWord);
        });

        // Make the cards visible
        $('.flashcard-container').hide().fadeIn(600);
    }

    // Add a word to the flashcard container at a random position
    function appendWordToContainer(wordData) {
        let container = $('<div>', {
            class: 'flashcard-container',
            'data-word': wordData.title
        }).hide();

        let fudgePixels = 10;

        if (llToolsFlashcardsData.displayMode === 'image') {
            $('<img>', {
                src: wordData.image,
                alt: wordData.title,
                class: 'quiz-image'
            }).on('load', function() {
                if (this.naturalWidth > (this.naturalHeight + fudgePixels)) {
                    container.addClass('landscape');
                    maxCardWidth = LANDSCAPE_CARD_WIDTH;
                } else if ((this.naturalWidth + fudgePixels) < this.naturalHeight) {
                    container.addClass('portrait');
                    maxCardHeight = PORTRAIT_CARD_HEIGHT;
                }
            }).appendTo(container);
        } else {

            container.addClass('text-based');

            let translationDiv = $('<div>', {
                text: wordData.translation,
                class: 'quiz-translation'
            });

            // Check text length and add long-text class if needed
            if (wordData.translation.length > 20) {
                translationDiv.addClass('long-text');
            }

            translationDiv.appendTo(container);
        }

        const insertAtIndex = Math.floor(Math.random() * ($('.flashcard-container').length + 1));
        if ($('.flashcard-container').length === 0 || insertAtIndex >= $('.flashcard-container').length) {
            $('#ll-tools-flashcard').append(container);
        } else {
            container.insertBefore($('.flashcard-container').eq(insertAtIndex));
        }
    }

    // Add click event to the card to handle right and wrong answers
    function addClickEventToCard(card, index, targetWord) {
        card.click(function() {
            if (llToolsFlashcardsData.displayMode === 'image') {
                if ($(this).find('img').attr("src") === targetWord.image) {
                    handleCorrectAnswer(targetWord);
                } else {
                    handleWrongAnswer(targetWord, index, $(this));
                }
            } else {
                if ($(this).find('.quiz-translation').text() === targetWord.translation) {
                    handleCorrectAnswer(targetWord);
                } else {
                    handleWrongAnswer(targetWord, index, $(this));
                }
            }
        });
    }

    // Respond to the correct answer
    function handleCorrectAnswer(targetWord) {
        // Correct answer logic
        FlashcardAudio.playFeedback(true, null, function () {
            // If there were any wrong answers before the right one was selected
            if (wrongIndexes.length > 0) {
                if (!categoryRepetitionQueues[currentCategoryName]) {
                    categoryRepetitionQueues[currentCategoryName] = [];
                }
                // Add the word to the repetition queue for the current category
                categoryRepetitionQueues[currentCategoryName].push({
                    wordData: targetWord,
                    reappearRound: categoryRoundCount[currentCategoryName] + randomIntFromInterval(1, 3), // Reappear in a few rounds
                });
            }

            // Track correct answers on the first try
            if (!quizResults.incorrect.includes(targetWord.id)) {
                quizResults.correctOnFirstTry += 1;
            }

            // Fade out and remove the wrong answers
            $('.flashcard-container').not(this).addClass('fade-out');

            // Wait for the transition to complete before moving to the next question
            setTimeout(function () {
                isFirstRound = false;
                showQuiz(); // Load next question after fade out
            }, 600); // Adjust the delay as needed to match the transition duration
        });
    }

    // Respond to the wrong answer
    function handleWrongAnswer(targetWord, index, wrongAnswer) {
        FlashcardAudio.playFeedback(false, targetWord.audio, null);

        // Fade out and remove the wrong answer card
        wrongAnswer.addClass('fade-out').one('transitionend', function () {
            wrongAnswer.remove(); // Remove the card completely after fade out
        });

        // Add the current index to the list of wrong answers
        if (!quizResults.incorrect.includes(targetWord.id)) {
            quizResults.incorrect.push(targetWord.id); // Track incorrect answers
        }

        // Add the index to wrongIndexes if the answer is incorrect
        wrongIndexes.push(index);

        // Check if the user has selected a wrong answer twice
        if (wrongIndexes.length === 2) {
            // Remove all options except the correct one
            $('.flashcard-container').not(function () {
                if (llToolsFlashcardsData.displayMode === 'image') {
                    return $(this).find('img').attr("src") === targetWord.image;
                } else {
                    return $(this).find('.quiz-translation').text() === targetWord.translation;
                }
            }).remove();
        }
    }

    function showLoadingAnimation() {
        $('#ll-tools-loading-animation').show();
    }

    function hideLoadingAnimation() {
        $('#ll-tools-loading-animation').hide();
    }
    // Expose hideLoadingAnimation globally for use in flashcard-audio.js
    window.hideLoadingAnimation = hideLoadingAnimation;

    function showQuiz(number_of_options) {
        if (isFirstRound) {
            // Load resources for the first few categories
            var initialCategories = categoryNames.slice(0, 3); // Adjust the number of initial categories as needed
            var categoryLoadPromises = initialCategories.map(function (categoryName) {
                return new Promise(function (resolve) {
                    FlashcardLoader.loadResourcesForCategory(categoryName, resolve);
                });
            });

            Promise.all(categoryLoadPromises)
                .then(function () {
                    // All initial categories loaded, proceed with the quiz
                    initializeOptionsCount(number_of_options);
                    startQuizRound();
                })
                .catch(function (error) {
                    console.error('Failed to load initial categories:', error);
                });
        } else {
            startQuizRound(); // Continue the quiz
        }
    }

    // Start a new round of the quiz
    function startQuizRound() {
        // Clear existing content from previous round
        $('#ll-tools-flashcard').empty();

        // Make sure the header is visible
        $('#ll-tools-flashcard-header').show();
        $('#ll-tools-skip-flashcard').show();
        $('#ll-tools-repeat-flashcard').show();

        FlashcardAudio.pauseAllAudio();
        showLoadingAnimation();
        FlashcardAudio.setTargetAudioHasPlayed(false);
        calculateNumberOfOptions();
        let targetWord = selectTargetWordAndCategory();

        if (!targetWord) {
            showResultsPage();
        } else {
            fillQuizOptions(targetWord);
            FlashcardAudio.setTargetWordAudio(targetWord);
        }
        userClickedSkip = false;
    }

    // Show results and the restart button
    function showResultsPage() {
        // Show the results div
        $('#quiz-results').show();

        // Hide the loading animation
        hideLoadingAnimation();

        // Hide the skip and repeat buttons
        $('#ll-tools-skip-flashcard').hide();
        $('#ll-tools-repeat-flashcard').hide();

        const totalQuestions = quizResults.correctOnFirstTry + quizResults.incorrect.length + quizResults.skipped;

        // Update the results dynamically
        $('#correct-count').text(quizResults.correctOnFirstTry);
        $('#total-questions').text(totalQuestions);
        $('#skipped-count').text(quizResults.skipped);

        // Ensure the "Restart Quiz" button is visible
        $('#restart-quiz').show();
    }

    function initFlashcardWidget(selectedCategories) {
        categoryNames = selectedCategories;
        categoryNames = randomlySort(categoryNames);

        firstCategoryName = categoryNames[0];
        FlashcardLoader.loadResourcesForCategory(firstCategoryName);

        // Disable scrolling
        $('body').addClass('ll-tools-flashcard-open');

        // Event handler for the close button
        $('#ll-tools-close-flashcard').off('click').on('click', function () {
            closeFlashcard();
        });

        $('#ll-tools-flashcard-header').show();

        // Event handler for the repeat button
        $('#ll-tools-repeat-flashcard').off('click').on('click', function () {
            var currentAudio = FlashcardAudio.getCurrentTargetAudio();
            if (currentAudio) {
                currentAudio.play().catch(function (e) {
                    console.error("Audio play failed:", e);
                });
            }
        });

        // Event handler for the skip button
        $('#ll-tools-skip-flashcard').off('click').on('click', function () {
            if (FlashcardAudio.getTargetAudioHasPlayed() && !userClickedSkip) {
                userClickedSkip = true;

                // Increment the skipped counter
                quizResults.skipped += 1;

                // Consider the skipped question as "wrong" when determining the number of options
                wrongIndexes.push(-1);

                isFirstRound = false; // Update the first round flag
                showQuiz(); // Move to the next question
            }
        });

        // Handle the Restart Quiz button click
        $('#restart-quiz').off('click').on('click', function () {
            restartQuiz();
        });

        showLoadingAnimation();
        showQuiz();
    }

    function closeFlashcard() {
        resetQuizState();
        wordsByCategory = {};
        categoryNames = [];
        FlashcardLoader.loadedCategories = [];
        FlashcardLoader.loadedResources = {};
        hideResultsPage();
        $('#ll-tools-flashcard').empty();
        $('#ll-tools-flashcard-header').hide();
        $('#ll-tools-flashcard-quiz-popup').hide();
        $('#ll-tools-flashcard-popup').hide();
        $('body').removeClass('ll-tools-flashcard-open');
    }

    function restartQuiz() {
        hideResultsPage();
        resetQuizState();
        showQuiz();
    }

    function hideResultsPage() {
        $('#quiz-results').hide();
        $('#restart-quiz').hide();
    }

    // Expose initFlashcardWidget function globally
    window.initFlashcardWidget = initFlashcardWidget;

})(jQuery);
