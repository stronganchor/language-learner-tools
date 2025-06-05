(function ($) {
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
    var userClickedCorrectAnswer = false;
    let quizResults = {
        correctOnFirstTry: 0,
        incorrect: []
    };

    /**
     * Returns the display mode for a given category name.
     *
     * @param {string} categoryName - The name of the category.
     * @returns {string} The display mode ('image' or 'text').
     */
    function getCategoryDisplayMode(categoryName) {
        if (!categoryName) return DEFAULT_DISPLAY_MODE;
        let catData = llToolsFlashcardsData.categories.find(cat => cat.name === categoryName);
        return catData ? catData.mode : DEFAULT_DISPLAY_MODE;
    }

    /**
     * Returns the display mode for the current category.
     *
     * @returns {string} The display mode ('image' or 'text').
     */
    function getCurrentDisplayMode() {
        return getCategoryDisplayMode(currentCategoryName);
    }

    /**
     * Resets the overall quiz state and prepares for a new quiz session.
     */
    function resetQuizState() {
        usedWordIDs = [];
        categoryRoundCount = {};
        wrongIndexes = [];
        currentCategory = null;
        currentCategoryName = null;
        currentCategoryRoundCount = 0;
        isFirstRound = true;
        categoryRepetitionQueues = {};
        resetQuizResults();
        hideResultsPage();
        FlashcardAudio.resetAudioState();
    }

    /**
     * Resets the quiz results counters.
     */
    function resetQuizResults() {
        quizResults = {
            correctOnFirstTry: 0,
            incorrect: []
        };
    }

    // Initialize audio functionality and load correct/wrong sounds
    FlashcardAudio.initializeAudio();
    FlashcardLoader.loadAudio(FlashcardAudio.getCorrectAudioURL());
    FlashcardLoader.loadAudio(FlashcardAudio.getWrongAudioURL());

    /**
     * Utility function to randomly sort an array.
     *
     * @param {Array} inputArray - Array to sort.
     * @returns {Array} A new, randomly sorted array.
     */
    function randomlySort(inputArray) {
        if (!Array.isArray(inputArray)) {
            return inputArray;
        }
        return [...inputArray].sort(() => 0.5 - Math.random());
    }

    /**
     * Returns a random integer between the specified min and max (inclusive).
     *
     * @param {number} min - Minimum value.
     * @param {number} max - Maximum value.
     * @returns {number} A random integer in [min, max].
     */
    function randomIntFromInterval(min, max) {
        return Math.floor((Math.random() * (max - min + 1)) + min);
    }

    /**
     * Attempts to select a target word from a specific category.
     * May also select from that category's repetition queue if applicable.
     *
     * @param {Array} candidateCategory - Words in the chosen category.
     * @param {string} candidateCategoryName - The name of the chosen category.
     * @returns {Object|null} The selected target word or null if none found.
     */
    function selectTargetWord(candidateCategory, candidateCategoryName) {
        if (!candidateCategory) {
            return null;
        }

        let target = null;
        const repeatQueue = categoryRepetitionQueues[candidateCategoryName];

        // Check if there are words due to reappear from the repetition queue
        if (repeatQueue && repeatQueue.length > 0) {
            for (let i = 0; i < repeatQueue.length; i++) {
                if (repeatQueue[i].reappearRound <= categoryRoundCount[candidateCategoryName]) {
                    target = repeatQueue[i].wordData;
                    repeatQueue.splice(i, 1);
                    break;
                }
            }
        }

        // If no word was found in the queue, pick a new one from the category
        if (!target) {
            for (let i = 0; i < candidateCategory.length; i++) {
                if (!usedWordIDs.includes(candidateCategory[i].id)) {
                    target = candidateCategory[i];
                    usedWordIDs.push(target.id);
                    break;
                }
            }
        }

        // If still no target, try the earliest item in the queue even if not scheduled yet
        if (!target && repeatQueue && repeatQueue.length > 0) {
            target = repeatQueue[0].wordData;

            // Avoid picking the exact same word consecutively if there's another choice
            if (target.id === usedWordIDs[usedWordIDs.length - 1]) {
                const otherWords = candidateCategory.filter(word => word.id !== target.id);
                if (otherWords.length > 0) {
                    target = otherWords[Math.floor(Math.random() * otherWords.length)];
                }
            }
            const targetWordIndex = repeatQueue.findIndex(item => item.wordData.id === target.id);
            if (targetWordIndex !== -1) {
                repeatQueue.splice(targetWordIndex, 1);
            }
        }

        // Update the global state if we selected a new target
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

    /**
     * Tries to select a target word from the next categories in sequence.
     *
     * @returns {Object|null} The selected word or null if none found.
     */
    function selectWordFromNextCategory() {
        for (let categoryName of categoryNames) {
            let targetWord = selectTargetWord(wordsByCategory[categoryName], categoryName);
            if (targetWord) {
                return targetWord;
            }
        }
        return null;
    }

    /**
     * Selects the target word and category for the next round.
     *
     * @returns {Object|null} The selected target word or null if quiz is finished.
     */
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
                // Move the current category to the end of the list
                categoryNames.splice(categoryNames.indexOf(currentCategoryName), 1);
                categoryNames.push(currentCategoryName);

                // Reset the round count for this category, then pick from the next
                categoryRoundCount[currentCategoryName] = 0;
                currentCategoryRoundCount = 0;
            }
        }

        if (!targetWord) {
            targetWord = selectWordFromNextCategory();
        }

        return targetWord;
    }

    /**
     * Selects words from a given category and appends them to the selected set,
     * avoiding duplicates, similar words, or repeated images.
     *
     * @param {string} category - Name of the category to fetch words from.
     * @param {Array} selectedWords - Already selected words for this round.
     * @returns {Array} Updated list of selected words.
     */
    function selectWordsFromCategory(category, selectedWords) {
        const displayMode = getCurrentDisplayMode();

        for (let candidateWord of wordsByCategory[category]) {
            let isDuplicate = selectedWords.some(word => word.id === candidateWord.id);
            let isSimilar = selectedWords.some(word => word.similar_word_id === candidateWord.id.toString() || candidateWord.similar_word_id === word.id.toString());
            let hasSameImage = (displayMode === 'image') && selectedWords.some(word => word.image === candidateWord.image);

            if (!isDuplicate && !isSimilar && !hasSameImage) {
                selectedWords.push(candidateWord);
                FlashcardLoader.loadResourcesForWord(candidateWord, displayMode);
                appendWordToContainer(candidateWord);

                if (selectedWords.length >= FlashcardOptions.categoryOptionsCount[currentCategoryName] ||
                    !FlashcardOptions.canAddMoreCards()) {
                    break;
                }
            }
        }
        return selectedWords;
    }

    /**
     * Builds out the quiz UI by filling options around the targetWord.
     *
     * @param {Object} targetWord - The word that the user must guess.
     */
    function fillQuizOptions(targetWord) {
        let selectedWords = [];
        let categoryNamesToTry = [];

        // Current category first
        categoryNamesToTry.push(currentCategoryName);

        // Then the target word's other categories
        if (targetWord.all_categories) {
            for (let category of targetWord.all_categories) {
                if (!categoryNamesToTry.includes(category)) {
                    categoryNamesToTry.push(category);
                }
            }
        }

        // Then any other categories
        categoryNames.forEach(category => {
            if (!categoryNamesToTry.includes(category)) {
                categoryNamesToTry.push(category);
            }
        });

        FlashcardLoader.loadResourcesForWord(targetWord, getCategoryDisplayMode());
        selectedWords.push(targetWord);
        appendWordToContainer(targetWord);

        while (
            selectedWords.length < FlashcardOptions.categoryOptionsCount[currentCategoryName] &&
            FlashcardOptions.canAddMoreCards()
        ) {
            if (categoryNamesToTry.length === 0 ||
                selectedWords.length >= FlashcardOptions.categoryOptionsCount[currentCategoryName]) {
                break;
            }
            let candidateCategoryName = categoryNamesToTry.shift();
            if (!wordsByCategory[candidateCategoryName] || FlashcardLoader.loadedCategories.includes(candidateCategoryName)) {
                FlashcardLoader.loadResourcesForCategory(candidateCategoryName);
            }
            wordsByCategory[candidateCategoryName] = randomlySort(wordsByCategory[candidateCategoryName]);
            selectedWords = selectWordsFromCategory(candidateCategoryName, selectedWords);
        }

        // Attach click handlers
        $('.flashcard-container').each(function (index) {
            addClickEventToCard($(this), index, targetWord);
        });

        // Fade in the new cards
        $('.flashcard-container').hide().fadeIn(600);
    }

    const _llTextMeasureCanvas = document.createElement('canvas');
    const _llTextMeasureCtx = _llTextMeasureCanvas.getContext('2d');

    /**
     * Measures the width of a given text string using a specified CSS font.
     * 
     * @param {string} text - The text to measure.
     * @param {string} cssFont - The CSS font string (e.g., "16px Arial").
     */
    function measureTextWidth(text, cssFont) {
        _llTextMeasureCtx.font = cssFont;
        return _llTextMeasureCtx.measureText(text).width;
    }

    /* ------------------------------------------------------------------
     *  Card-construction helpers
     * ------------------------------------------------------------------ */

    /**
     * Builds and returns an image-mode flashcard <div>.
     * @param  {Object} wordData  – The word object from the AJAX response
     * @return {jQuery}           – A hidden (display:none) jQuery element
     */
    function createImageCard(wordData) {
        const container = $('<div>', {
            class: 'flashcard-container flashcard-size-' + llToolsFlashcardsData.imageSize,
            'data-word': wordData.title,
            css: { display: 'none' }
        });

        $('<img>', {
            src: wordData.image,
            alt: wordData.title,
            class: 'quiz-image'
        }).on('load', function () {
            const fudge = 10;
            if (this.naturalWidth > this.naturalHeight + fudge) {
                container.addClass('landscape');
            } else if (this.naturalWidth + fudge < this.naturalHeight) {
                container.addClass('portrait');
            }
        }).appendTo(container);

        return container;
    }

    /**
     * Builds and returns a text-mode flashcard <div>.
     * Dynamically chooses the largest font size that fits.
     *
     * @param  {Object} wordData – The word object
     * @return {jQuery}          – A hidden (display:none) jQuery element
     */
    function createTextCard(wordData) {

        // give the card the same size bucket as image cards
        const sizeClass = 'flashcard-size-' + llToolsFlashcardsData.imageSize;

        const container = $('<div>', {
            class: `flashcard-container text-based ${sizeClass}`,
            'data-word': wordData.title
        });

        const labelDiv = $('<div>', {
            text: wordData.label,
            class: 'quiz-text'
        }).appendTo(container);

        /* ——— Measure off-screen ——— */
        container.css({
            position: 'absolute',
            top: -9999,
            left: -9999,
            visibility: 'hidden',
            display: 'block'      // let it render so width/height are real
        })
            .appendTo('body');

        const boxH = container.innerHeight() - 15;
        const boxW = container.innerWidth() - 15;
        const fontFamily = getComputedStyle(labelDiv[0]).fontFamily || 'sans-serif';

        for (let fs = 48; fs >= 12; fs--) {
            const textWidth = measureTextWidth(wordData.label, fs + 'px ' + fontFamily);
            if (textWidth > boxW) {
                continue; // too wide, try smaller fs
            }

            labelDiv.css({
                'font-size': fs + 'px',
                'line-height': fs + 'px',
                visibility: 'visible',    // only for height measurement
                position: 'relative'
            });
            const textHeight = labelDiv.outerHeight();
            if (textHeight <= boxH) {
                break;
            }
            // else: too tall, try next smaller fs
        }

        /* ——— Clean-up ——— */
        container.detach().css({ position: '', top: '', left: '', visibility: '', display: 'none' });

        return container;
    }

    /**
     * Inserts a flashcard container at a random position within the flashcard widget.
     * 
     * @param {jQuery} container - The jQuery element representing the flashcard container to insert.
     */
    function insertContainerAtRandom(container) {
        const $cards = $('#ll-tools-flashcard .flashcard-container');
        const idx = Math.floor(Math.random() * ($cards.length + 1));

        if (!$cards.length || idx >= $cards.length) {
            $('#ll-tools-flashcard').append(container);
        } else {
            container.insertBefore($cards.eq(idx));
        }
        container.fadeIn(200);
    }

    /**
     * Appends a word to the flashcard container based on the current display mode.
     * 
     * @param {Object} wordData - The word data object containing the word's details.
     */
    function appendWordToContainer(wordData) {
        const displayMode = getCurrentDisplayMode(); // "image" | "text"
        const card = (displayMode === 'image')
            ? createImageCard(wordData)
            : createTextCard(wordData);

        insertContainerAtRandom(card);
    }

    /**
     * Adds a click event to a card for right/wrong answer handling.
     *
     * @param {jQuery} card - The jQuery element for the card.
     * @param {number} index - Index of the card.
     * @param {Object} targetWord - The target word object to compare with.
     */
    function addClickEventToCard(card, index, targetWord) {
        const displayMode = getCurrentDisplayMode();

        card.click(function () {
            if (displayMode === 'image') {
                if ($(this).find('img').attr("src") === targetWord.image) {
                    handleCorrectAnswer(targetWord, $(this));
                } else {
                    handleWrongAnswer(targetWord, index, $(this));
                }
            } else {
                if ($(this).find('.quiz-text').text() === (targetWord.label || '')) {
                    handleCorrectAnswer(targetWord, $(this));
                } else {
                    handleWrongAnswer(targetWord, index, $(this));
                }
            }
        });
    }

    /**
     * Handles a correct answer by applying visuals, playing audio feedback,
     * and scheduling the next round.
     *
     * @param {Object} targetWord - The target word object.
     * @param {jQuery} correctCard - The card element that was clicked.
     */
    function handleCorrectAnswer(targetWord, correctCard) {
        if (userClickedCorrectAnswer) {
            return;
        }
        correctCard.addClass('correct-answer');

        // Determine where on the screen to launch confetti
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
            // If there were any wrong answers previously
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

            // Fade out and remove the incorrect cards
            $('.flashcard-container').not(correctCard).addClass('fade-out');

            setTimeout(function () {
                isFirstRound = false;
                userClickedCorrectAnswer = false;
                startQuizRound();
            }, 600);
        });
    }

    /**
     * Handles a wrong answer by playing feedback, removing the wrong card,
     * and potentially removing all but the correct card on second wrong attempt.
     *
     * @param {Object} targetWord - The target word object.
     * @param {number} index - Index of the card that was clicked.
     * @param {jQuery} wrongAnswer - The jQuery element of the wrong card.
     */
    function handleWrongAnswer(targetWord, index, wrongAnswer) {
        FlashcardAudio.playFeedback(false, targetWord.audio, null);

        // Fade out the incorrect card
        wrongAnswer.addClass('fade-out').one('transitionend', function () {
            wrongAnswer.remove();
        });

        if (!quizResults.incorrect.includes(targetWord.id)) {
            quizResults.incorrect.push(targetWord.id);
        }
        wrongIndexes.push(index);

        const displayMode = getCurrentDisplayMode();
        if (wrongIndexes.length === 2) {
            // Remove all options except the correct one
            $('.flashcard-container').not(function () {
                if (displayMode === 'image') {
                    return $(this).find('img').attr("src") === targetWord.image;
                } else {
                    return $(this).find('.quiz-text').text() === (targetWord.label || '');
                }
            }).remove();
        }
    }

    /**
     * Displays the loading animation.
     */
    function showLoadingAnimation() {
        $('#ll-tools-loading-animation').show();
    }

    /**
     * Hides the loading animation.
     */
    function hideLoadingAnimation() {
        $('#ll-tools-loading-animation').hide();
    }
    // Expose hideLoadingAnimation globally for reference in other files
    window.hideLoadingAnimation = hideLoadingAnimation;

    /**
     * Sets up the quiz round. If it's the first round, attempts to load
     * the first chunk of categories before starting. Otherwise, runs directly.
     *
     * @param {number} number_of_options - Optional initial number of options for the quiz.
     */
    function startQuizRound(number_of_options) {
        if (isFirstRound) {
            const firstThreeCategories = categoryNames.slice(0, 3);

            // Load the first chunk of the first category, then proceed
            FlashcardLoader.loadResourcesForCategory(firstThreeCategories[0], function () {
                FlashcardOptions.initializeOptionsCount(number_of_options);
                runQuizRound();
            });

            // Preload remaining categories in the background
            if (firstThreeCategories.length > 1) {
                for (let i = 1; i < firstThreeCategories.length; i++) {
                    FlashcardLoader.loadResourcesForCategory(firstThreeCategories[i]);
                }
            }
        } else {
            runQuizRound();
        }
    }

    /**
     * Runs the logic for each quiz round: clears previous data, sets up new,
     * picks a target word, and displays the options.
     */
    function runQuizRound() {
        // Clear UI from previous round
        $('#ll-tools-flashcard').empty();
        $('#ll-tools-flashcard-header').show();
        $('#ll-tools-repeat-flashcard').show();

        FlashcardAudio.pauseAllAudio();
        showLoadingAnimation();
        FlashcardAudio.setTargetAudioHasPlayed(false);

        // Decide the number of options for this round
        FlashcardOptions.calculateNumberOfOptions(wrongIndexes, isFirstRound, currentCategoryName);

        // Pick the target word
        let targetWord = selectTargetWordAndCategory();

        // If no word is returned, the quiz is finished
        if (!targetWord) {
            showResultsPage();
            return;
        }

        // Load resources for the target word first
        FlashcardLoader.loadResourcesForWord(targetWord, getCategoryDisplayMode())
            .then(function () {
                // Build UI options around the target word
                fillQuizOptions(targetWord);
                FlashcardAudio.setTargetWordAudio(targetWord);
                hideLoadingAnimation();
            });
    }

    /**
     * Fires confetti from either a specific origin or from both sides of the screen.
     *
     * @param {Object} options - Configuration for the confetti.
     */
    function startConfetti(options) {
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

    /**
     * Shows the final quiz results, including correct/total counts and a progress message.
     */
    function showResultsPage() {
        const totalQuestions = quizResults.correctOnFirstTry + quizResults.incorrect.length;

        // Check if no results were found
        if (totalQuestions === 0) {
            $('#quiz-results-title').text(llToolsFlashcardsMessages.somethingWentWrong);
            $('#quiz-results-message').hide();
            $('#quiz-results').show();
            $('#restart-quiz').hide();
            FlashcardAudio.playFeedback(false, null, null);
            return;
        }
        $('#quiz-results').show();
        hideLoadingAnimation();
        $('#ll-tools-repeat-flashcard').hide();

        $('#correct-count').text(quizResults.correctOnFirstTry);
        $('#total-questions').text(totalQuestions);
        $('#restart-quiz').show();

        const correctRatio = (totalQuestions > 0) ? (quizResults.correctOnFirstTry / totalQuestions) : 0;
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
                'color': '#555'
            });
            $message.show();
        }

        // If the user scored 70% or better, trigger confetti
        if (correctRatio >= 0.7) {
            startConfetti();
        }
    }

    /**
     * Initializes the flashcard quiz with chosen categories, sets up event handlers,
     * and starts the first quiz round.
     *
     * @param {Array} selectedCategories - The category names selected for the quiz.
     */
    function initFlashcardWidget(selectedCategories) {
        categoryNames = selectedCategories;
        categoryNames = randomlySort(categoryNames);
        firstCategoryName = categoryNames[0];
        FlashcardLoader.loadResourcesForCategory(firstCategoryName);

        // Disable scrolling while quiz is open
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
                if (!currentAudio.paused) {
                    // Pause and revert to play
                    currentAudio.pause();
                    currentAudio.currentTime = 0;
                    $(this).html('<span class="icon-container"><img src="' + llToolsFlashcardsData.plugin_dir + 'media/play-symbol.svg" alt="Play"></span>');
                    $(this).removeClass('stop-mode').addClass('play-mode');
                } else {
                    // Play and switch to stop
                    currentAudio.play().then(() => {
                        $('#ll-tools-repeat-flashcard').html('<span class="icon-container"><img src="' + llToolsFlashcardsData.plugin_dir + 'media/stop-symbol.svg" alt="Stop"></span>');
                        $('#ll-tools-repeat-flashcard').removeClass('play-mode').addClass('stop-mode');
                    }).catch(e => {
                        console.error("Audio play failed:", e);
                    });
                    // When audio ends, revert to play
                    currentAudio.onended = function () {
                        $('#ll-tools-repeat-flashcard').html('<span class="icon-container"><img src="' + llToolsFlashcardsData.plugin_dir + 'media/play-symbol.svg" alt="Play"></span>');
                        $('#ll-tools-repeat-flashcard').removeClass('stop-mode').addClass('play-mode');
                    };
                }
            }
        });

        // Handle the Restart Quiz button click
        $('#restart-quiz').off('click').on('click', function () {
            restartQuiz();
        });

        showLoadingAnimation();
        startQuizRound();
    }

    /**
     * Closes the flashcard quiz and restores page state.
     */
    function closeFlashcard() {
        resetQuizState();
        categoryNames = [];

        $('#ll-tools-flashcard').empty();
        $('#ll-tools-flashcard-header').hide();
        $('#ll-tools-flashcard-quiz-popup').hide();
        $('#ll-tools-flashcard-popup').hide();
        $('body').removeClass('ll-tools-flashcard-open');
    }

    /**
     * Restarts the quiz from the beginning.
     */
    function restartQuiz() {
        resetQuizState();
        startQuizRound();
    }

    /**
     * Hides the results page section.
     */
    function hideResultsPage() {
        $('#quiz-results').hide();
        $('#restart-quiz').hide();
    }

    // Expose relevant functions globally
    window.initFlashcardWidget = initFlashcardWidget;
    window.getCategoryDisplayMode = getCategoryDisplayMode;
    window.getCurrentDisplayMode = getCurrentDisplayMode;

    // Preload the first category if categories are preselected
    if (llToolsFlashcardsData.categoriesPreselected) {
        FlashcardLoader.processFetchedWordData(llToolsFlashcardsData.firstCategoryData, firstCategoryName);
        FlashcardLoader.preloadCategoryResources(firstCategoryName);
    }

})(jQuery);
