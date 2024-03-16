jQuery(document).ready(function($) {
	const ROUNDS_PER_CATEGORY = 6;
	const MINIMUM_NUMBER_OF_OPTIONS = 2;
	const MINIMUM_WORDS_PER_CATEGORY = 4;
	
	var usedWordIDs = []; // set of IDs of words we've covered so far (resets when no words are left to show)
    var activeAudios = [];
	var wordsByCategory = {}; // key: category name string => value: set of word objects corresponding to that category
	var categoryNames = []; // all the category names
	var categoryRoundCount = {}; // key: category name string => value: number of rounds where we used a word from this category
    var wordsData = llToolsFlashcardsData.words || []; // Set globally with a fallback to an empty array
    var translations = llToolsFlashcardsData.translations || [];
    var wrongIndexes = []; // To track indexes of wrong answers this turn
    var targetAudioHasPlayed = false;
    var currentTargetAudio = null;
	var defaultNumberOfOptions = 4;
	var currentCategory = null;
	var currentCategoryName = null;
	var currentCategoryRoundCount = 0;
    var maxCards = null;
    var isFirstRound = true;
	var categoryOptionsCount = {}; // To track the number of options for each category
    var categoryRepetitionQueues = {}; // To manage separate repetition queues for each category

	var loadedResources = {};

	// Load resources for a word
	function loadResourcesForWord(word) {
		// Load image
		if (!loadedResources[word.image]) {
			fetch(word.image)
				.then(response => response.blob())
				.then(blob => {
					let url = URL.createObjectURL(blob);
					let img = new Image();
					img.onload = function() {
						URL.revokeObjectURL(url);
						loadedResources[word.image] = true;
					};
					img.src = url;
				});
		}

		// Load audio
		if (!loadedResources[word.audio]) {
			fetch(word.audio)
				.then(response => response.blob())
				.then(blob => {
					let url = URL.createObjectURL(blob);
					let audio = new Audio();
					audio.onload = function() {
						URL.revokeObjectURL(url);
						loadedResources[word.audio] = true;
					};
					audio.src = url;
				});
		}
	}

	// Load resources for a category
	function loadResourcesForCategory(categoryName) {
		for (let word of wordsByCategory[categoryName]) {
			loadResourcesForWord(word);
		}
	}
    
    // Audio feedback elements
    var correctAudio = new Audio(llToolsFlashcardsData.plugin_dir + './right-answer.mp3');
    var wrongAudio = new Audio(llToolsFlashcardsData.plugin_dir + './wrong-answer.mp3');

	// Save the quiz state to user metadata in WordPress
    function saveQuizState() {
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
	
	// load saved quiz state from user metadata
	function loadQuizState() {
	    var savedQuizState = llToolsFlashcardsData.quizState;
	    if (savedQuizState) {
	        // Parse the saved quiz state from JSON
	        var quizState = JSON.parse(savedQuizState);
	
	        // Validate each property before applying it
	        usedWordIDs = Array.isArray(quizState.usedWordIDs) ? quizState.usedWordIDs : [];
	        categoryRoundCount = typeof quizState.categoryRoundCount === 'object' ? quizState.categoryRoundCount : {};
	        
	        // Validate categoryOptionsCount entries
	        let savedOptionsCount = typeof quizState.categoryOptionsCount === 'object' ? quizState.categoryOptionsCount : {};
	        for (let category in savedOptionsCount) {
	            if (savedOptionsCount.hasOwnProperty(category)) {
	                let count = parseInt(categoryOptionsCount[category]);
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
	
    // Calculate the maximum number of cards that can fit in the available space for the widget
    function calculateMaxCards() {
        var containerWidth = $('#ll-tools-flashcard-container').width(); // Get the width of the container
        var containerElement = document.getElementById('ll-tools-flashcard'); // Ensure this is the ID of your flex container
        var style = window.getComputedStyle(containerElement);
        var gap = parseInt(style.getPropertyValue('gap'), 10); // Get gap as an integer

        // Check if gap is NaN (Not a Number), set to 0 if true
        if (isNaN(gap)) {
            gap = 0;
        }

        // Create a temporary card to measure dimensions
        var tempCard = $('<div>', { class: 'flashcard-image-container' }).appendTo('#ll-tools-flashcard');
        var cardWidth = tempCard.outerWidth(true); // Includes padding, border, and margin
        var cardHeight = tempCard.outerHeight(true); // Includes padding, border, and margin
        tempCard.remove(); // Remove the temporary card after measurement

        // Calculate available height from container's top to bottom of the screen
        var containerOffset = $('#ll-tools-flashcard').offset().top; // Get the top position of the container relative to the document
        var screenHeight = $(window).height(); // Get the height of the screen
        var availableHeight = screenHeight - containerOffset - gap; // Subtract gap from available height to account for vertical spacing

        // Adjusting availableHeight to account for additional padding/margin at the bottom
        var buffer = 20;
        availableHeight -= buffer;

        // Calculate how many cards can fit horizontally and vertically, accounting for the gap
        var fitHorizontal = Math.max(1, Math.floor((containerWidth + gap) / (cardWidth + gap)));
        var fitVertical = Math.max(1, Math.floor((availableHeight + gap) / (cardHeight + gap)));

        // Calculate maximum cards based on current layout
        maxCards = Math.max(MINIMUM_NUMBER_OF_OPTIONS, fitHorizontal * fitVertical);

		// Make sure the max is not more than the number of available words
		maxCards = Math.min(maxCards, wordsData.length);
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
		if(!maxCards) {
			calculateMaxCards();
		}
		let maxOptionsCount = maxCards;
		if (wordsByCategory[categoryName]) {
			// Limit the number of options to the total number of words in this category, or however many will fit on the screen
			maxOptionsCount = Math.min(maxOptionsCount, wordsByCategory[categoryName].length);
		}
		maxOptionsCount = Math.max(MINIMUM_NUMBER_OF_OPTIONS, maxOptionsCount);
		return Math.min(Math.max(MINIMUM_NUMBER_OF_OPTIONS, optionsCount), maxOptionsCount)
	}

	// Initialize category arrays
	function initializeCategoryArrays() {
		wordsData.forEach(word => {
			let categoryName = word.category;
			if (!wordsByCategory[categoryName]) {
				wordsByCategory[categoryName] = [];
				categoryRoundCount[categoryName] = 0;
			}
			wordsByCategory[categoryName].push(word);
		});

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
        }

		// Randomize the order of the categories to begin with
		categoryNames = Object.keys(wordsByCategory);
		categoryNames = randomlySort(categoryNames);
	}

	// Set up initial values for the number of options to display for each category of words
	function initializeOptionsCount(number_of_options) {
		calculateMaxCards();
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
		let number_of_options = categoryOptionsCount[currentCategoryName] ;
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
	
		if (!isFirstRound) {
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
			let hasSameImage = selectedWords.some(word => word.image === candidateWord.image);
			if (!isDuplicate && !isSimilar && !hasSameImage) {
				selectedWords.push(candidateWord); // Add to the selected words if it passes checks
				if (selectedWords.length >= categoryOptionsCount[currentCategoryName]) {
					break;
				}
			}
		}
		return selectedWords;
	}
	
	function fillQuizOptions(targetWord) {
		let selectedWords = [];
		selectedWords.push(targetWord);
		
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
		
		while (selectedWords.length < categoryOptionsCount[currentCategoryName]) {
			if (tryCategories.length === 0 || selectedWords.length >= categoryOptionsCount[currentCategoryName]) break;
			let candidateCategory = tryCategories.shift();
			wordsByCategory[candidateCategory] = randomlySort(wordsByCategory[candidateCategory]);
			selectedWords = selectWordsFromCategory(candidateCategory, selectedWords);
		}
		
		// Load all resources for the selected words
		selectedWords.forEach(word => loadResourcesForWord(word));

		return selectedWords; // Return the filled options
	}
	
	function shuffleAndDisplayWords(selectedWords, targetWord) {
		// Shuffle the selected words to ensure the target is not always first
		selectedWords = randomlySort(selectedWords);

		// Clear existing content and pause all audio
		$('#ll-tools-flashcard').empty();
		pauseAllAudio();

		// Iterate through the shuffled words and create HTML elements for them
		selectedWords.forEach((wordData, index) => {
			let imgContainer = $('<div>', {
				class: 'flashcard-image-container',
			}).hide().fadeIn(600); // Create a container for each image

			let img = $('<img>', {
				src: wordData.image,
				alt: wordData.title,
				class: 'quiz-image'
			}).appendTo(imgContainer); // Append the image to its container

			// Check the image orientation and apply the appropriate class
			img.on('load', function() {
				if (this.naturalWidth > this.naturalHeight) {
					imgContainer.addClass('landscape');
				} else {
					imgContainer.addClass('portrait');
				}
			});

			// Add click event for the word
			imgContainer.click(function() {
				if (wordData.title === targetWord.title) { // Correct answer
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
						
						// Fade out the correct answer after the audio finishes
						imgContainer.addClass('fade-out').one('transitionend', function() {
							isFirstRound = false;
                            showQuiz(); // Load next question after fade out
                        });
					});
					// Fade out other answers immediately by adding 'fade-out' class
					$('.flashcard-image-container').not(this).addClass('fade-out');
				} else { // Wrong answer
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
						$('.flashcard-image-container').not(function() {
							return $(this).find('img').attr('alt') === targetWord.title;
						}).remove();
					}					
				}
			});

			// Append the container to the main quiz element
			$('#ll-tools-flashcard').append(imgContainer);
			imgContainer.fadeIn(200); // Ensure fadeIn effect for each image container
		});
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
	
	function finalizeQuizSetup() {
		// Hide any elements not needed during the quiz, for example, the start button
		$('#ll-tools-start-flashcard').addClass('hidden');

		// Show the quiz container if it was previously hidden
		$('#ll-tools-flashcard').removeClass('hidden');
}

    function showQuiz(number_of_options) {
		if (isFirstRound) {
			// Initialize the category arrays and options count
			initializeCategoryArrays();
			
			// Asynchronously load resources for all categories
			for (let categoryName of categoryNames) {
				setTimeout(() => loadResourcesForCategory(categoryName), 0);
			}

			initializeOptionsCount(number_of_options);
		}
		
        targetAudioHasPlayed = false;
		
        calculateNumberOfOptions();
             
        let targetWord  = selectTargetWordAndCategory();
		
        selectedWords = fillQuizOptions(targetWord);

        shuffleAndDisplayWords(selectedWords, targetWord);

        setTargetWordAudio(targetWord);

        finalizeQuizSetup();

		saveQuizState();		
    }  
        
    // Decide which function to call based on the mode passed from PHP
    function initFlashcardWidget() {
        var mode = llToolsFlashcardsData.mode; // Access the mode

		loadQuizState();
		
        // Create and insert the Skip button next to the Start/Repeat button
        $('<button>', {
            text: translations.skip, // Using the translated string for 'Skip'
            id: 'll-tools-skip-flashcard',
            class: 'flashcard-skip-button' // Add your CSS class for styling the button
        }).insertAfter('#ll-tools-start-flashcard');
    
        // Event handler for the Skip button
        $('#ll-tools-skip-flashcard').on('click', function() {
            // Consider the skipped question as wrong
            wrongIndexes.push(-1);
            isFirstRound = false; // Update the first round flag
            showQuiz(); // Move to the next question
        });
        
        // Until other modes are implemented, always run in the quiz mode
        showQuiz();
    }
    
    // Event handler to start the widget or replay audio
    $('#ll-tools-start-flashcard').on('click', function() {
        // Check if the button has been clicked before
        if ($(this).hasClass('clicked')) {
            // Button has been clicked before, so replay the audio
            if (currentTargetAudio) {
                currentTargetAudio.play().catch(function(e) {
                    console.error("Audio play failed:", e);
                });
            }
        } else {
            // Initialize the flashcard widget
            initFlashcardWidget();
            // Change the button text to "Repeat"
            $(this).text(translations.repeat);
            // Mark the button as clicked
            $(this).addClass('clicked');
        }
    });
});
