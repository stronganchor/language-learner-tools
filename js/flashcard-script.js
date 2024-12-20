(function($) {
    const ROUNDS_PER_CATEGORY = 6;
    const DEFAULT_DISPLAY_MODE = "image";

    // Attach shared variables to window
    window.categoryNames = []; // The category names selected for the current quiz
    window.wordsByCategory = {}; // Maps category names to arrays of word objects
    window.categoryRoundCount = {}; // Tracks rounds per category
    window.firstCategoryName = llToolsFlashcardsData.firstCategoryName;

    // Variables related to the quiz state
    var usedWordIDs = []; // Set of IDs of words we've covered so far
    var wrongIndexes = []; // Tracks indexes of wrong answers this turn
    var currentCategory = null;
    var currentCategoryName = null;
    var currentCategoryRoundCount = 0;
    var isFirstRound = true;
    var categoryRepetitionQueues = {}; // Manages separate repetition queues for each category
    var userClickedSkip = false;
    var userClickedCorrectAnswer = false;
    let quizResults = {
        correctOnFirstTry: 0,
        incorrect: [],
        skipped: 0
    };

    function getCategoryDisplayMode(categoryName) {
        if (!categoryName) return DEFAULT_DISPLAY_MODE;
        let catData = llToolsFlashcardsData.categories.find(cat => cat.name === categoryName);
        return catData ? catData.mode : DEFAULT_DISPLAY_MODE; 
    }
    
    function getCurrentDisplayMode() {
        return getCategoryDisplayMode(currentCategoryName);
    }

    function resetQuizState() {
        usedWordIDs = [];
        categoryRoundCount = {};
        wrongIndexes = [];
        currentCategory = null;
        currentCategoryName = null;
        currentCategoryRoundCount = 0;
        isFirstRound = true;
        categoryRepetitionQueues = {};
        userClickedSkip = false;
        resetQuizResults();
        hideResultsPage();
        FlashcardAudio.resetAudioState();
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
        FlashcardLoader.processFetchedWordData(llToolsFlashcardsData.firstCategoryData, firstCategoryName);
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
        return Math.floor((Math.random() * (max - min + 1)) + min);
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
                const otherWords = candidateCategory.filter(word => word.id !== target.id);
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
        const displayMode = getCurrentDisplayMode();

        for (let candidateWord of wordsByCategory[category]) {
            // Check if the candidate word is not already selected and not similar to any selected words
            let isDuplicate = selectedWords.some(word => word.id === candidateWord.id);
            let isSimilar = selectedWords.some(word => word.similar_word_id === candidateWord.id.toString() || candidateWord.similar_word_id === word.id.toString());
            let hasSameImage = displayMode === 'image' && selectedWords.some(word => word.image === candidateWord.image);

            // If this word passes the checks, add it to the selected words and container
            if (!isDuplicate && !isSimilar && !hasSameImage) {
                selectedWords.push(candidateWord);
                FlashcardLoader.loadResourcesForWord(candidateWord, displayMode);
                appendWordToContainer(candidateWord);

                if (selectedWords.length >= FlashcardOptions.categoryOptionsCount[currentCategoryName] || !FlashcardOptions.canAddMoreCards()) {
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

        FlashcardLoader.loadResourcesForWord(targetWord, getCategoryDisplayMode());
        selectedWords.push(targetWord);
        appendWordToContainer(targetWord);

        while (selectedWords.length < FlashcardOptions.categoryOptionsCount[currentCategoryName] &&
               FlashcardOptions.canAddMoreCards()) {
            if (categoryNamesToTry.length === 0 || 
                selectedWords.length >= FlashcardOptions.categoryOptionsCount[currentCategoryName]) {
                break;
            }
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
        const displayMode = getCurrentDisplayMode();
        let container = $('<div>', {
            class: 'flashcard-container',
            'data-word': wordData.title
        }).hide();

        let fudgePixels = 10;

        if (displayMode === 'image') {
            $('<img>', {
                src: wordData.image,
                alt: wordData.title,
                class: 'quiz-image'
            }).on('load', function() {
                if (this.naturalWidth > (this.naturalHeight + fudgePixels)) {
                    container.addClass('landscape');
                } else if ((this.naturalWidth + fudgePixels) < this.naturalHeight) {
                    container.addClass('portrait');
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
        const displayMode = getCurrentDisplayMode();

        card.click(function() {
            if (displayMode === 'image') {
                if ($(this).find('img').attr("src") === targetWord.image) {
                    handleCorrectAnswer(targetWord, $(this));
                } else {
                    handleWrongAnswer(targetWord, index, $(this));
                }
            } else {
                if ($(this).find('.quiz-translation').text() === targetWord.translation) {
                    handleCorrectAnswer(targetWord, $(this));
                } else {
                    handleWrongAnswer(targetWord, index, $(this));
                }
            }
        });
    }
    
    function handleCorrectAnswer(targetWord, correctCard) {
        if (userClickedCorrectAnswer){
            return;
        }

        correctCard.addClass('correct-answer');
        const rect = correctCard[0].getBoundingClientRect();
        const xPos = (rect.left + rect.width / 2) / window.innerWidth;
        const yPos = (rect.top + rect.height / 2) / window.innerHeight;
        
        userClickedCorrectAnswer = true;

        startConfetti({
            particleCount: 20,
            angle: 90,
            spread: 60,
            origin: { x: xPos, y: yPos }, 
            duration: 50
        });
    
        FlashcardAudio.playFeedback(true, null, function () {
            // If there were any wrong answers before the right one was selected
            if (wrongIndexes.length > 0) {
                if (!categoryRepetitionQueues[currentCategoryName]) {
                    categoryRepetitionQueues[currentCategoryName] = [];
                }
                // Add the word to the repetition queue for the current category
                categoryRepetitionQueues[currentCategoryName].push({
                    wordData: targetWord,
                    reappearRound: categoryRoundCount[currentCategoryName] + randomIntFromInterval(1, 3),
                });
            }
    
            // Track correct answers on the first try
            if (!quizResults.incorrect.includes(targetWord.id)) {
                quizResults.correctOnFirstTry += 1;
            }
    
            // Fade out and remove the wrong answers
            $('.flashcard-container').not(correctCard).addClass('fade-out');
    
            // Wait for the transition to complete before moving to the next question
            setTimeout(function () {
                isFirstRound = false;
                userClickedCorrectAnswer = false;
                startQuizRound(); // Load next question after fade out
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

        const displayMode = getCurrentDisplayMode();
        
        // Check if the user has selected a wrong answer twice
        if (wrongIndexes.length === 2) {
            // Remove all options except the correct one
            $('.flashcard-container').not(function () {
                if (displayMode === 'image') {
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

    function startQuizRound(number_of_options) {
        if (isFirstRound) {
            // Load resources for the first few categories
            var initialCategories = categoryNames.slice(0, 3); // Adjust the number of initial categories as needed
            var categoryLoadPromises = initialCategories.map(function (categoryName) {
                return new Promise(function (resolve) {
                    FlashcardLoader.loadResourcesForCategory(categoryName, resolve);
                });
            });
    
            Promise.all(categoryLoadPromises)
                .then(function() {
                    FlashcardOptions.initializeOptionsCount(number_of_options);
    
                    // Continue with the first quiz round
                    runQuizRound();
                })
                .catch(function(error) {
                    console.error('Failed to load initial categories:', error);
                });
        } else {
            // Proceed directly with the quiz round
            runQuizRound();
        }
    }
    
    // Helper function for proceeding with a quiz round
    function runQuizRound() {
        // Clear existing content from previous round
        $('#ll-tools-flashcard').empty();
    
        // Make sure the header is visible
        $('#ll-tools-flashcard-header').show();
        $('#ll-tools-skip-flashcard').show();
        $('#ll-tools-repeat-flashcard').show();
    
        FlashcardAudio.pauseAllAudio();
        showLoadingAnimation();
        FlashcardAudio.setTargetAudioHasPlayed(false);
        FlashcardOptions.calculateNumberOfOptions(wrongIndexes, isFirstRound, currentCategoryName);
        let targetWord = selectTargetWordAndCategory();
    
        if (!targetWord) {
            showResultsPage();
        } else {
            fillQuizOptions(targetWord);
            FlashcardAudio.setTargetWordAudio(targetWord);
        }
        userClickedSkip = false;
    }    

    function startConfetti(options) {
        // Default options if nothing is passed
        const defaults = {
            particleCount: 6,
            angle: 60,
            spread: 55,
            origin: null, // null by default, meaning no fixed origin specified
            duration: 2000 // duration in ms
        };
    
        // Merge defaults with passed-in options
        const settings = Object.assign({}, defaults, options);
    
        try {
            var confettiCanvas = document.getElementById('confetti-canvas');
            if (!confettiCanvas) {
                confettiCanvas = document.createElement('canvas');
                confettiCanvas.id = 'confetti-canvas';
                confettiCanvas.style.position = 'fixed';
                confettiCanvas.style.top = '0px';
                confettiCanvas.style.left = '0px';
                confettiCanvas.style.width = '100%';
                confettiCanvas.style.height = '100%';
                confettiCanvas.style.pointerEvents = 'none';
                confettiCanvas.style.zIndex = '999999';
                document.body.appendChild(confettiCanvas);
            }
    
            if (typeof confetti === 'function') {
                var myConfetti = confetti.create(confettiCanvas, {
                    resize: true,
                    useWorker: true
                });
    
                var end = Date.now() + settings.duration;
    
                (function frame() {
                    if (settings.origin && typeof settings.origin.x === 'number' && typeof settings.origin.y === 'number') {
                        // If an origin is specified, launch confetti from that exact spot
                        myConfetti({
                            particleCount: settings.particleCount,
                            angle: settings.angle,
                            spread: settings.spread,
                            origin: { x: settings.origin.x, y: settings.origin.y }
                        });
                    } else {
                        // No origin specified, launch from left and right sides
                        myConfetti({
                            particleCount: Math.floor(settings.particleCount / 2),
                            angle: settings.angle,
                            spread: settings.spread,
                            origin: { x: 0.0, y: 0.5 }
                        });
    
                        myConfetti({
                            particleCount: Math.ceil(settings.particleCount / 2),
                            angle: 120, // opposite angle
                            spread: settings.spread,
                            origin: { x: 1.0, y: 0.5 }
                        });
                    }
    
                    if (Date.now() < end) {
                        requestAnimationFrame(frame);
                    }
                }());
            } else {
                console.warn('Confetti not available. Skipping confetti animation.');
            }
        } catch (e) {
            console.warn('Confetti initialization failed. Skipping confetti animation.', e);
        }
    }    

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
    
        // Calculate the user's score percentage
        const correctRatio = (totalQuestions > 0) ? (quizResults.correctOnFirstTry / totalQuestions) : 0;
    
        // Select the title and message elements
        const $title = $('#quiz-results-title');
        const $message = $('#quiz-results-message');
    
        // Decide on the encouragement message
        if (correctRatio === 1) {
            // Perfect score
            $title.text(llToolsFlashcardsMessages.perfect);
            $message.hide();
        } else if (correctRatio >= 0.7) {
            // Good job score
            $title.text(llToolsFlashcardsMessages.goodJob);
            $message.hide();
        } else {
            // Below 70%
            $title.text(llToolsFlashcardsMessages.keepPracticingTitle);
            $message.text(llToolsFlashcardsMessages.keepPracticingMessage);
            $message.css({
                'font-size': '14px',
                'margin-top': '10px',
                'color': '#555', // Optional for styling
            });
            $message.show();
        }
    
        // If the user scored 70% or better, trigger confetti
        if (correctRatio >= 0.7) {
            startConfetti();
        }
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
                currentAudio.play().catch(function(e) {
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
                startQuizRound(); // Move to the next question
            }
        });

        // Handle the Restart Quiz button click
        $('#restart-quiz').off('click').on('click', function () {
            restartQuiz();
        });

        showLoadingAnimation();
        startQuizRound();
    }

    function closeFlashcard() {
        resetQuizState();
        categoryNames = [];
        
        $('#ll-tools-flashcard').empty();
        $('#ll-tools-flashcard-header').hide();
        $('#ll-tools-flashcard-quiz-popup').hide();
        $('#ll-tools-flashcard-popup').hide();
        $('body').removeClass('ll-tools-flashcard-open');
    }

    function restartQuiz() {
        resetQuizState();
        startQuizRound();
    }

    function hideResultsPage() {
        $('#quiz-results').hide();
        $('#restart-quiz').hide();
    }

    // Expose functions globally
    window.initFlashcardWidget = initFlashcardWidget;
    window.getCategoryDisplayMode = getCategoryDisplayMode;
    window.getCurrentDisplayMode = getCurrentDisplayMode;

})(jQuery);
