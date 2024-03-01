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
		
    // Function to show a quiz with random images and play audio from one of them
	function showQuiz(number_of_options = 4, categories = {}) {
        // Adjust number of options based on previous round's answers, keeping the number between the minimum (2) and maxCards
        numberOfOptionsThisRound = calculateNumberOfOptions(number_of_options);

        // Reset wrongIndexes for this round
        wrongIndexes = [];
		
		targetAudioHasPlayed = false;
		
		if (wordsData.length < numberOfOptionsThisRound) {
			alert("Not enough words for a quiz.");
			return;
		}

		// Reset usedIndexes if all words have been used as targets
		if (usedIndexes.length >= wordsData.length) {
			usedIndexes = [];
		}

		// Pre-process wordsData into categories
		if (!Array.isArray(categories) || categories.length === 0) {
			wordsData.forEach(word => {
				if (!categories[word.category]) {
					categories[word.category] = [];
				}
				categories[word.category].push(word);
			});
		}
		
		let categoryNames = Object.keys(categories);
		let selectedWords = [];
		let selectedCategory = [];
		let targetWord = null;
		let attempts = 0;
		let maxCategoryAttempts = categoryNames.length; // Limit attempts to the number of categories to avoid infinite loops

		while (!targetWord && attempts < maxCategoryAttempts) {
			// Select a random category
			let randomIndex = Math.floor(Math.random() * categoryNames.length);
			let selectedCategoryName = categoryNames[randomIndex];
			selectedCategory = Array.from(categories[selectedCategoryName]);

			// Remove the chosen category from the list to avoid repeating the same category
			categoryNames.splice(randomIndex, 1);

			// Attempt to select a target word from the chosen category
			for (let i = 0; i < selectedCategory.length; i++) {
				if (usedIndexes.indexOf(selectedCategory[i].id) === -1) { // Ensure this word hasn't been recently used as a target
					targetWord = selectedCategory.splice(i, 1)[0]; // Remove selected target word from category array
					usedIndexes.push(targetWord.id); // Add to usedIndexes as it's now the target
					selectedWords.push(targetWord);
					break;
				}
			}

			// Increment attempts after trying each category
			attempts++;
		}
		
		// Fill the rest of the quiz slots from the selected category, avoiding similar and duplicate words
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

		// If not enough words in the category, fill from other categories
		let filledOptions = false; // Flag to check if enough options have been filled
		let categoryAttempted = new Set(); // Keep track of categories already attempted

		while (selectedWords.length < numberOfOptionsThisRound && categoryAttempted.size < categoryNames.length) {
			let otherCategoryName = categoryNames.filter(name => !categoryAttempted.has(name))[Math.floor(Math.random() * (categoryNames.length - categoryAttempted.size))];
			let otherCategory = categories[otherCategoryName];
			categoryAttempted.add(otherCategoryName); // Mark this category as attempted

			let localAttempts = 0; // Local attempts for words in this category
			while (localAttempts < otherCategory.length) {
				let candidateIndex = Math.floor(Math.random() * otherCategory.length);
				let candidateWord = otherCategory[candidateIndex]; 

				let isDuplicate = selectedWords.some(word => word.id === candidateWord.id);
				let isSimilar = selectedWords.some(word => word.similar_word_id === candidateWord.id.toString() || candidateWord.similar_word_id === word.id.toString());

				if (!isDuplicate && !isSimilar) {
					selectedWords.push(candidateWord); // Add to the selected words if it passes checks
					filledOptions = true; // We've successfully added a word, so mark this flag as true
					if (selectedWords.length >= numberOfOptionsThisRound) break; // Break if we've filled the required options
				}

				// Remove this word from the array after evaluation to avoid rechecking
				otherCategory.splice(candidateIndex, 1);
				localAttempts++; // Increment local attempt count
			}

			if (filledOptions && selectedWords.length >= numberOfOptionsThisRound) break; // Break the main loop if we've filled all options
		}

		// Randomize the order of selected words to ensure the target is not always first
		selectedWords = selectedWords.sort(() => Math.random() - 0.5);

		// Pause audio & clear existing content
		pauseAllAudio();
        $('#ll-tools-flashcard').empty();

        let correctContainer; // To keep track of the correct answer's container

        selectedWords.forEach((wordData, index) => {
            let imgContainer = $('<div>', { class: 'flashcard-image-container' }).hide().fadeIn(600);
            let img = $('<img>', {
                src: wordData.image,
                alt: wordData.title,
                class: 'quiz-image'
            }).appendTo(imgContainer);

            imgContainer.click(function() {
                if(wordData.title === targetWord.title) {
                    playFeedback(true, null, function() {
                        // Fade out the correct answer after the audio finishes
                        correctContainer.addClass('fade-out').one('transitionend', function() {
							isFirstRound = false;
                            showQuiz(numberOfOptionsThisRound, categories); // Load next question after fade out
                        });
                    });
                    // Fade out other answers immediately by adding 'fade-out' class
                    $('.flashcard-image-container').not(this).addClass('fade-out');
                    correctContainer = imgContainer; // Keep track of the correct answer's container
                } else {
                    playFeedback(false, targetWord.audio, null);
                    // Fade out and remove the wrong answer
                    $(this).addClass('fade-out').one('transitionend', function() {
                        $(this).remove(); // Remove the card completely after fade out
                    });
                    // Add index to wrongIndexes if the answer is incorrect
                    wrongIndexes.push(index);
                }
            });

            $('#ll-tools-flashcard').append(imgContainer);
            imgContainer.fadeIn(200); // Ensure fadeIn effect for each container
        });
		
		// Play the audio of the target word
		var audio = $('<audio>', {
			src: targetWord.audio,
			controls: true
		}).appendTo('#ll-tools-flashcard')[0];

		currentTargetAudio = audio;
		playAudio(audio);
		
		// Add event listener to prevent clicking for the first half second of a round
		audio.addEventListener('timeupdate', function() {
			// Check if the audio has played at least for 0.4 seconds
			if (audio.currentTime > 0.4 || audio.ended) {
				targetAudioHasPlayed = true;
				// Optionally remove the event listener if it's no longer needed
				audio.removeEventListener('timeupdate', arguments.callee);
			}
		});

        $('#ll-tools-flashcard').removeClass('hidden');
        $('#ll-tools-start-flashcard').addClass('hidden');
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
