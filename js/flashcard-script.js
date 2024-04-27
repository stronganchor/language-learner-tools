(function($) {
	const ROUNDS_PER_CATEGORY = 6;
	const MINIMUM_NUMBER_OF_OPTIONS = 2;
	const DEFAULT_NUMBER_OF_OPTIONS = 2;
	const MAXIMUM_NUMBER_OF_OPTIONS = 9;
	const MAXIMUM_TEXT_OPTIONS = 4; // Limit text-based quizzes to 4 options per round
	const MAX_ROWS = 3;
	
	var usedWordIDs = []; // set of IDs of words we've covered so far (resets when no words are left to show)
    var activeAudios = [];
	var wordsByCategory = {}; // key: category name string => value: set of word objects corresponding to that category
	var categoryNames = []; // all the category names
	var loadedCategories = []; // categories that have been loaded
	var categoryRoundCount = {}; // key: category name string => value: number of rounds where we used a word from this category
    var translations = llToolsFlashcardsData.translations || [];
    var wrongIndexes = []; // To track indexes of wrong answers this turn
    var targetAudioHasPlayed = false;
    var currentTargetAudio = null;
	var currentCategory = null;
	var currentCategoryName = null;
	var currentCategoryRoundCount = 0;
    var isFirstRound = true;
	var categoryOptionsCount = {}; // To track the number of options for each category
    var categoryRepetitionQueues = {}; // To manage separate repetition queues for each category
	var loadedResources = {};
	var firstCategoryData = llToolsFlashcardsData.firstCategoryData;
	var firstCategoryName = llToolsFlashcardsData.firstCategoryName;

	// Preload the first category
	processFetchedWordData(firstCategoryData, firstCategoryName);

    // Audio feedback elements
    var correctAudio = new Audio(llToolsFlashcardsData.plugin_dir + './media/right-answer.mp3');
    var wrongAudio = new Audio(llToolsFlashcardsData.plugin_dir + './media/wrong-answer.mp3');

	// Preload resources for the first target word and its category
	//loadResourcesForCategory(firstCategory);
	loadAudio(correctAudio.src);
	loadAudio(wrongAudio.src);

	// Load an audio file so that it's cached for later use
	function loadAudio(audioURL) {
		if (!loadedResources[audioURL] && audioURL) {
			new Promise((resolve, reject) => {
				let audio = new Audio(audioURL);
				audio.oncanplaythrough = function() {
					resolve(audio);
				};
				audio.onerror = function() {
					reject(new Error('Audio load failed'));
				};
			});
			loadedResources[audioURL] = true;
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
				img.src = imageURL
			});
			loadedResources[imageURL] = true;
		}
	}

	// Load resources for a word
	function loadResourcesForWord(word) {
		loadAudio(word.audio);
		if (llToolsFlashcardsData.displayMode === 'image') {
			loadImage(word.image);
		}
	}

	function processFetchedWordData(wordData, categoryName) {
		if (!wordsByCategory[categoryName]) {
			wordsByCategory[categoryName] = [];
		}
		if (!categoryRoundCount[categoryName]) {
			categoryRoundCount[categoryName] = 0;
		}
		
		// For each loaded word
		wordData.forEach(function(word) {			
			wordsByCategory[categoryName].push(word);
			loadResourcesForWord(word);
		});

		// Randomize the order of the words in this category
		wordsByCategory[categoryName] = randomlySort(wordsByCategory[categoryName]);
		// Add the category to the list of loaded categories
		loadedCategories.push(categoryName);
	}

	// Load resources for a category
	function loadResourcesForCategory(categoryName, callback) {
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
		var numberToPreload = 3; // Adjust the number of categories to preload as needed

		// for all category names
		categoryNames.forEach(function(categoryName) {
			// if the category is not loaded
			if (!loadedCategories.includes(categoryName)) {
				// load the resources for the category
				loadResourcesForCategory(categoryName);
				numberToPreload--;
				if (numberToPreload === 0) {
					return;
				}
			}
		});
	}

	// Save the quiz state to user metadata in WordPress
    function saveQuizState() {
		if (llToolsFlashcardsData.isUserLoggedIn) {	
			var quizState = {
				usedWordIDs: usedWordIDs,
				categoryRoundCount: categoryRoundCount,
				categoryOptionsCount: categoryOptionsCount,
				categoryRepetitionQueues: categoryRepetitionQueues
			};

        	// Send an AJAX request to save the quiz state
			$.ajax({
				url: llToolsFlashcardsData.ajaxurl, // WordPress AJAX URL
				method: 'POST',
				data: {
					action: 'll_save_quiz_state',
					quiz_state: JSON.stringify(quizState)
				}
			});
		}
    }
	
	// load saved quiz state from user metadata
	function loadQuizState() {
		var savedQuizState = llToolsFlashcardsData.quizState;
	    if (llToolsFlashcardsData.isUserLoggedIn && savedQuizState) {
	        // Parse the saved quiz state from JSON
	        var quizState = JSON.parse(savedQuizState);
	
	        // Validate each property before applying it
	        usedWordIDs = Array.isArray(quizState.usedWordIDs) ? quizState.usedWordIDs : [];
	        categoryRoundCount = typeof quizState.categoryRoundCount === 'object' ? quizState.categoryRoundCount : {};
	        
	        // Validate categoryOptionsCount entries
	        let savedOptionsCount = typeof quizState.categoryOptionsCount === 'object' ? quizState.categoryOptionsCount : {};
	        for (let category in savedOptionsCount) {
	            if (savedOptionsCount.hasOwnProperty(category)) {
	                let count = parseInt(savedOptionsCount[category]);
	                categoryOptionsCount[category] = checkMinMax(count, category);
	            }
	        }
	        
	        categoryRepetitionQueues = typeof quizState.categoryRepetitionQueues === 'object' ? quizState.categoryRepetitionQueues : {};
	    }
	}
	
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
		const cardWidth = lastCard.outerWidth(true);
		const cardHeight = lastCard.outerHeight(true);
	
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
    
    // Helper function for playing audio
    function playAudio(audio) {
        try {
            if (!audio.paused) {
                audio.pause();
                audio.currentTime = 0;
            }
        } catch(e) {
            console.error("Audio pause failed:", e);
        }
        
        activeAudios.push(audio);
        audio.play().catch(function(e) {
            console.error("Audio play failed:", e);
        });
    }
    
    // Pause all audio elements
    function pauseAllAudio() {
        activeAudios.forEach(function(audio) {
            try {
                audio.pause();
                audio.currentTime = 0; // Reset the playback position
            } catch(e) {
                console.error("Audio pause failed:", e);
            }
        });
        activeAudios = [];
    }
    
    function playFeedback(isCorrect, targetWordAudio, callback) {
        var audioToPlay = isCorrect ? correctAudio : wrongAudio;
        
        // Don't register clicks for the first half second & don't repeat the 'correct' sound
        if (!targetAudioHasPlayed || (isCorrect && !audioToPlay.paused)) {
            return;
        }

        playAudio(audioToPlay);

        if (!isCorrect && targetWordAudio) {
            // When the wrong answer sound finishes, play the target word's audio
            wrongAudio.onended = function() {
                playAudio(currentTargetAudio);
            };
        } else if (isCorrect && typeof callback === 'function') {
            // When the correct answer sound finishes, execute the callback
            correctAudio.onended = callback;
        }
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

	// Initialize category arrays
	function initializeCategoryArrays() {
		
		/* TODO: refactor this code to handle the new AJAX paradigm

		// Validation step: Ensure each category has enough words
        for (let categoryName in wordsByCategory) {
            if (wordsByCategory[categoryName].length < MINIMUM_WORDS_PER_CATEGORY) {
                let wordsToMove = [...wordsByCategory[categoryName]]; // Create a copy of the array
                for (let word of wordsToMove) {
                    for (let otherCategory of word.all_categories) {
                        if (otherCategory !== categoryName && wordsByCategory[otherCategory] && wordsByCategory[otherCategory].length >= MINIMUM_WORDS_PER_CATEGORY) {
                            // Move the word to the other category
                            wordsByCategory[otherCategory].push(word);
                            let index = wordsByCategory[categoryName].indexOf(word);
                            if (index > -1) {
                                wordsByCategory[categoryName].splice(index, 1);
                            }
                            break;
                        }
                    }
                }
            }
        }

        // Remove categories that don't have enough words
        for (let categoryName in wordsByCategory) {
            if (wordsByCategory[categoryName].length < MINIMUM_WORDS_PER_CATEGORY) {
                delete wordsByCategory[categoryName];
            }
        }*/

		// Randomize the order of the categories to begin with
		categoryNames = llToolsFlashcardsData.categories;
		categoryNames = randomlySort(categoryNames);
	}

	// Set up initial values for the number of options to display for each category of words
	function initializeOptionsCount(number_of_options) {
		if (number_of_options) {
			DEFAULT_NUMBER_OF_OPTIONS = number_of_options;
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
				categoryOptionsCount[categoryName] = checkMinMax(Math.min(wordsByCategory[categoryName].length, DEFAULT_NUMBER_OF_OPTIONS), categoryName);
		} else {
			// Handle any mismatches between categoryNames and wordsByCategory arrays
			categoryOptionsCount[categoryName] = checkMinMax(DEFAULT_NUMBER_OF_OPTIONS, categoryName);
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
			
			// Remove the target word from the repeat queue if applicable
			const targetWordIndex = repeatQueue.findIndex(item => item.wordData.id === target.id);
			if (targetWordIndex !== -1) {
				repeatQueue.splice(targetWordIndex, 1);
			}

			usedWordIDs.push(target.id);
		}

		if (target) {
			if (currentCategoryName !== candidateCategoryName) {
				currentCategoryName = candidateCategoryName;
				currentCategoryRoundCount = 0;
				preloadNextCategories();
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
			targetWord = selectTargetWord(wordsByCategory[firstCategoryName], firstCategoryName);
			currentCategoryName = firstCategoryName;
			currentCategory = wordsByCategory[currentCategoryName];
		} else {
			const repeatQueue = categoryRepetitionQueues[currentCategoryName];
			if ((repeatQueue && repeatQueue.length > 0) || currentCategoryRoundCount < ROUNDS_PER_CATEGORY) {
				targetWord = selectTargetWord(currentCategory, currentCategoryName);
			} else {	
				// Put the current category at the end of the category names list
				categoryNames.splice(categoryNames.indexOf(currentCategoryName), 1);
				categoryNames.push(currentCategoryName);
			}
		}
	
		if (!targetWord) {
			targetWord = selectWordFromNextCategory();
		}
	
		// If no target word is found after going through all categories, reset usedWordIDs and try again
		if (!targetWord) {
			usedWordIDs = [];
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
				loadResourcesForWord(candidateWord);
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
	
		tryCategories = [];
	
		// The first category to try will be the current category
		tryCategories.push(currentCategoryName);
	
		// If we don't find enough words in the current category, look at this word's other categories
		if (targetWord.all_categories) {
			for (let category of targetWord.all_categories) {
				if (!tryCategories.includes(category)) {
					tryCategories.push(category);
				}
			}
		}
	
		// If that still isn't enough, look at all other categories
		categoryNames.forEach(category => {
			if (!tryCategories.includes(category)) {
				tryCategories.push(category);
			}
		});

		// Clear existing content and pause all audio
		$('#ll-tools-flashcard').empty();
		pauseAllAudio();
	
		loadResourcesForWord(targetWord);
		selectedWords.push(targetWord);
		appendWordToContainer(targetWord);

		while (selectedWords.length < categoryOptionsCount[currentCategoryName] && canAddMoreCards()) {
			if (tryCategories.length === 0 || selectedWords.length >= categoryOptionsCount[currentCategoryName]) break;
			let candidateCategory = tryCategories.shift();
			if (wordsByCategory[candidateCategory] && loadedCategories.includes(candidateCategory)) {
				wordsByCategory[candidateCategory] = randomlySort(wordsByCategory[candidateCategory]);
				selectedWords = selectWordsFromCategory(candidateCategory, selectedWords);
			} else {
				// If the words data is not loaded, request it and continue with the next category
				loadResourcesForCategory(candidateCategory);
			}
		}
		
		// Add click events to the cards
		$('.flashcard-container').each(function(index) {
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
				} else if ((this.naturalWidth + fudgePixels) < this.naturalHeight) {
					container.addClass('portrait');
				}
			}).appendTo(container);
		} else {
			let translationDiv = $('<div>', {
				text: wordData.translation,
				class: 'quiz-translation'
			});
		
			// Check text length and add long-text class if needed
			if (wordData.translation.length > 12) {
				translationDiv.addClass('long-text');
			}
		
			translationDiv.appendTo(container);
		}
		
		const insertAtIndex = Math.floor(Math.random() * ($('.flashcard-container').length + 1));
		if ($('.flashcard-container').length === 0 || insertAtIndex >= $('.flashcard-container').length) {
			$('#ll-tools-flashcard').append(container);
		} else {
			// Insert the container at the specified index
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
					handleWrongAnswer(targetWord, index);
				}
			} else {
				if ($(this).find('.quiz-translation').text() === targetWord.translation) {
					handleCorrectAnswer(targetWord);
				} else {
					handleWrongAnswer(targetWord, index);
				}
			}
		});
	}

	// Respond to the correct answer
	function handleCorrectAnswer(targetWord) {
		// Correct answer
		playFeedback(true, null, function() {
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
			// Fade out and remove the wrong answers
			$('.flashcard-container').not(this).addClass('fade-out');

			// Wait for the transition to complete before moving to the next question
			setTimeout(function() {
				isFirstRound = false;
				showQuiz(); // Load next question after fade out
			}, 600); // Adjust the delay as needed to match the transition duration
		});
	}

	// Respond to the wrong answer
	function handleWrongAnswer(targetWord, index) {
		playFeedback(false, targetWord.audio, null);
		// Fade out and remove the wrong answer
		$(this).addClass('fade-out').one('transitionend', function() {
			$(this).remove(); // Remove the card completely after fade out
		});

		// Add index to wrongIndexes if the answer is incorrect
		wrongIndexes.push(index);

		// Check if the user has selected a wrong answer twice
		if (wrongIndexes.length === 2) {
			// Remove all options except the correct one
			$('.flashcard-container').not(function() {
				if (llToolsFlashcardsData.displayMode === 'image') {
					return $(this).find('img').attr("src") === targetWord.image;
				} else {
					return $(this).find('.quiz-translation').text() === targetWord.translation;
				}
			}).remove();
		}
	}

	function setTargetWordAudio(targetWord) {
		// Clear existing audio elements to prevent overlaps
		$('#ll-tools-flashcard audio').remove();

		// Create the audio element for the target word
		let audioElement = $('<audio>', {
			src: targetWord.audio,
			controls: true
		}).appendTo('#ll-tools-flashcard'); // Append it to the container

		// Global variable update for current audio, adapting from the original structure
		currentTargetAudio = audioElement[0];

		// Begin playback and manage interaction based on playback status
		playAudio(currentTargetAudio); // Play the target word's audio

		// Prevent clicking (interaction) before the audio has played for a certain time
		currentTargetAudio.addEventListener('timeupdate', function() {

			// If the loading animation is still visible, hide it
			if ((this.currentTime > 0.1 || this.ended) && $('#ll-tools-loading-animation').is(':visible')) {
				hideLoadingAnimation();
			}

			if (this.currentTime > 0.4 || this.ended) {
				targetAudioHasPlayed = true; // Allow interactions with quiz options
				// Optionally remove the event listener if it's no longer needed to avoid memory leaks
				this.removeEventListener('timeupdate', arguments.callee);
			}
		});

		// Adjust visibility and state of elements based on audio playback; similar adjustments might be needed
		currentTargetAudio.onended = function() {
			// Logic upon ending audio can be adapted here if necessary
			// For example, enabling certain buttons or changing states
		};
	}

	function showLoadingAnimation() {
		$('#ll-tools-loading-animation').show();
	}

	function hideLoadingAnimation() {
		$('#ll-tools-loading-animation').hide();
	}
	
	function finalizeQuizSetup() {
		// TODO: consider removing this function if it's not needed
	}

    function showQuiz(number_of_options) {
		if (isFirstRound) {		
			// Set up category arrays and preload the first category
			initializeCategoryArrays();
			
			// Load resources for the first few categories
			var initialCategories = categoryNames.slice(0, 3); // Adjust the number of initial categories as needed
			var categoryLoadPromises = initialCategories.map(function(categoryName) {
				return new Promise(function(resolve) {
					loadResourcesForCategory(categoryName, resolve);
				});
			});
	
			Promise.all(categoryLoadPromises).then(function() {
				// All initial categories loaded, proceed with the quiz
				initializeOptionsCount(number_of_options);
				startQuizRound();
			}).catch(function(error) {
				console.error('Failed to load initial categories:', error);
				// TODO: Handle the error gracefully, e.g., show an error message to the user
			});
		} else {
			startQuizRound();
		}	
    }  

	// Start a new round of the quiz
	function startQuizRound() {		
		showLoadingAnimation();
		targetAudioHasPlayed = false;
		calculateNumberOfOptions();
		let targetWord = selectTargetWordAndCategory();
		fillQuizOptions(targetWord);
		setTargetWordAudio(targetWord);
		finalizeQuizSetup();
		saveQuizState();
	}
        
    // Decide which function to call based on the mode passed from PHP
    function initFlashcardWidget() {
        var mode = llToolsFlashcardsData.mode; // Access the mode

		loadQuizState();
		
		$('#ll-tools-flashcard-popup').show();

		// Event handler for the close button
		$('#ll-tools-close-flashcard').on('click', function() {
			$('#ll-tools-flashcard-popup').hide();
			$('body').removeClass('ll-tools-flashcard-open');
		});

		// Event handler for the repeat button
		$('#ll-tools-repeat-flashcard').on('click', function() {
			if (currentTargetAudio) {
				currentTargetAudio.play().catch(function(e) {
					console.error("Audio play failed:", e);
				});
			}
		});

		// Event handler for the skip button
		$('#ll-tools-skip-flashcard').on('click', function() {
			// Consider the skipped question as wrong
			wrongIndexes.push(-1);
			isFirstRound = false; // Update the first round flag
			showQuiz(); // Move to the next question
		});
    }

    // Event handler to start the widget
	$('#ll-tools-start-flashcard').on('click', function() {
		initFlashcardWidget();
		showLoadingAnimation();
		showQuiz();		
		$('body').addClass('ll-tools-flashcard-open'); 
	});

})(jQuery);
