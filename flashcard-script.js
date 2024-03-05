jQuery(document).ready(function($) {
    var usedIndexes = []; // For quiz mode to track used words
    var activeAudios = [];
    var wordsData = llToolsFlashcardsData.words || []; // Set globally with a fallback to an empty array
    var translations = llToolsFlashcardsData.translations || [];
    var wrongIndexes = []; // To track indexes of wrong answers this turn
    var targetAudioHasPlayed = false;
    var currentTargetAudio = null;
    var numberOfOptionsThisRound = null;
    var maxCards = null;
    var minCards = 2;
    var isFirstRound = true;

    var imagesToLoad = wordsData.map(word => word.image);
    imagesToLoad.forEach(function(src) {
        var img = new Image();
        img.src = src;
    });
    
    // Audio feedback elements
    var correctAudio = new Audio(llToolsFlashcardsData.plugin_dir + './right-answer.mp3');
    var wrongAudio = new Audio(llToolsFlashcardsData.plugin_dir + './wrong-answer.mp3');
    
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
        maxCards = Math.max(minCards, fitHorizontal * fitVertical);
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
    
    // Calculate the number of options in a quiz dynamically based on user answers
    function calculateNumberOfOptions(lastNumberOfOptions) {
        var number_of_options = -1;
        if (isFirstRound) {
            calculateMaxCards();
            number_of_options = Math.min(Math.max(minCards, lastNumberOfOptions), maxCards);
        } else if (wrongIndexes.length > 0) {
            // Show fewer options if the user got it wrong last round
            number_of_options = Math.max(minCards, lastNumberOfOptions - 1);
        } else {
            // Add in more options if the user got the last card right on the first try
            number_of_options = Math.min(maxCards, lastNumberOfOptions + 1);
        }
        return number_of_options;
    }
    
	function selectTargetWordAndCategory(categories) {
		let categoryNames = Object.keys(categories);
		// Randomly reorder the list of category names
		categoryNames.sort(() => Math.random() - 0.5);

		let selectedWords = [];
		let targetWord = null;

		// Iterate over the shuffled category names
		for (let selectedCategoryName of categoryNames) {
			if (targetWord) break; // Exit the loop if we have found a target word

			let selectedCategory = Array.from(categories[selectedCategoryName]);

			// Attempt to select a target word from the chosen category
			for (let i = 0; i < selectedCategory.length; i++) {
				if (!usedIndexes.includes(selectedCategory[i].id)) { // Ensure this word hasn't been recently used as a target
					targetWord = selectedCategory.splice(i, 1)[0]; // Remove selected target word from category array
					usedIndexes.push(targetWord.id); // Add to usedIndexes as it's now the target
					selectedWords.push(targetWord);
					break; // Exit the loop once the target word is selected
				}
			}
		}
		return { selectedWords, targetWord };
	}
	
	function fillQuizOptions(selectedWords, selectedCategory, categories, numberOfOptionsThisRound) {
		// First, try to fill the quiz options from the same category as the target word
		while (selectedWords.length < numberOfOptionsThisRound && selectedCategory.length > 0) {
			let candidateIndex = Math.floor(Math.random() * selectedCategory.length);
			let candidateWord = selectedCategory[candidateIndex];

			// Check if the candidate word is not already selected and not similar to any selected words
			let isDuplicate = selectedWords.some(word => word.id === candidateWord.id);
			let isSimilar = selectedWords.some(word => word.similar_word_id === candidateWord.id.toString() || candidateWord.similar_word_id === word.id.toString());

			if (!isDuplicate && !isSimilar) {
				selectedWords.push(candidateWord); // Add to the selected words if it passes checks
			}

			// Remove the word from the category array after evaluation to prevent infinite looping
			selectedCategory.splice(candidateIndex, 1);
		}

		// If there are not enough words in the same category, fill from other categories
		if (selectedWords.length < numberOfOptionsThisRound) {
			let otherCategoryNames = Object.keys(categories).filter(name => !selectedWords.some(word => categories[name].includes(word)));

			// Shuffle other categories to randomize the selection process
			otherCategoryNames.sort(() => Math.random() - 0.5);

			for (let categoryName of otherCategoryNames) {
				if (selectedWords.length >= numberOfOptionsThisRound) break; // Break if we have enough options

				let otherCategory = categories[categoryName];
				for (let i = 0; i < otherCategory.length; i++) {
					if (selectedWords.length >= numberOfOptionsThisRound) break; // Check again as we might have reached the number inside the loop

					let candidateWord = otherCategory[i];
					// Ensure this new candidate is not a duplicate or similar to the existing selections
					let isDuplicate = selectedWords.some(word => word.id === candidateWord.id);
					let isSimilar = selectedWords.some(word => word.similar_word_id === candidateWord.id.toString() || candidateWord.similar_word_id === word.id.toString());

					if (!isDuplicate && !isSimilar) {
						selectedWords.push(candidateWord);
					}
				}
			}
		}

		return selectedWords; // Return the filled options
	}
	
	function shuffleAndDisplayWords(selectedWords, targetWord, categories) {
		// Shuffle the selected words to ensure the target is not always first
		selectedWords.sort(() => 0.5 - Math.random());

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

			// Add click event for the word
			imgContainer.click(function() {
				if (wordData.title === targetWord.title) { // Correct answer
					playFeedback(true, null, function() {
						// Fade out the correct answer after the audio finishes
						imgContainer.addClass('fade-out').one('transitionend', function() {
							isFirstRound = false;
                            showQuiz(numberOfOptionsThisRound, categories); // Load next question after fade out
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

		// Additionally, if there are other UI elements that need to be reset or shown, handle them here
		// For example, resetting form fields, hiding feedback messages, etc.

		// If there is logic that sets up the initial state of the quiz (like enabling buttons, resetting timers, etc.), include it here
		// For instance, if you have a 'Submit' button or similar controls for the quiz, ensure they are in the correct state

		// If the quiz involves any kind of timer or countdown, initialize or reset it here as well

		// Ensure all quiz options are now clickable or interactable as needed, if there's any global disabling prior

		// Any additional UI adjustments or state resets that typically occur at the start of a quiz should be included
	}

    // Revised showQuiz function
    function showQuiz(number_of_options = 4, categories = {}) {
        
        // Adjust number of options based on previous round's answers, keeping the number between the minimum (2) and maxCards
        numberOfOptionsThisRound = calculateNumberOfOptions(number_of_options);
        // Reset wrongIndexes for this round
        wrongIndexes = [];
        targetAudioHasPlayed = false;
        
        // Initialize categories array
        if (!Array.isArray(categories) || categories.length === 0) {
			wordsData.forEach(word => {
				if (!categories[word.category]) {
					categories[word.category] = [];
				}
				categories[word.category].push(word);
			});
		}

        if (wordsData.length < numberOfOptionsThisRound) {
            alert("Not enough words for a quiz.");
            return;
        }

        let { selectedWords, targetWord } = selectTargetWordAndCategory(categories);

        selectedWords = fillQuizOptions(selectedWords, categories[selectedWords[0].category], categories, numberOfOptionsThisRound);

        shuffleAndDisplayWords(selectedWords, targetWord, categories);

        setTargetWordAudio(targetWord);

        finalizeQuizSetup();
    }  
        
    // Decide which function to call based on the mode passed from PHP
    function initFlashcardWidget() {
        var mode = llToolsFlashcardsData.mode; // Access the mode

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
            showQuiz(numberOfOptionsThisRound); // Move to the next question
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
