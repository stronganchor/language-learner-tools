jQuery(document).ready(function($) {	
	var currentIndex = 0;
	var usedIndexes = []; // For quiz mode to track used words
	var wordsData = llToolsFlashcardsData.words || []; // Set globally with a fallback to an empty array

	// Audio feedback elements
	var correctAudio = new Audio('/wp-content/uploads/2024/02/right-answer.mp3');
	var wrongAudio = new Audio('/wp-content/uploads/2024/02/wrong-answer.mp3');
	
	function playFeedback(isCorrect) {
		if (isCorrect) {
			// If the right answer audio is playing, pause and rewind it before playing the next audio
			if (!correctAudio.paused) {
				correctAudio.pause();
				correctAudio.currentTime = 0;
			}
			correctAudio.play();
		} else {
			// If the wrong answer audio is playing, pause and rewind it before playing the next audio
			if (!wrongAudio.paused) {
				wrongAudio.pause();
				wrongAudio.currentTime = 0;
			}
			wrongAudio.play();
		}
	}
	
    function showRandomWord() {
		if (wordsData.length === 0) {
			alert("No words found.");
			return;
		}

		var randomIndex = Math.floor(Math.random() * wordsData.length);
		var wordData = wordsData[randomIndex];

		// Clear existing content and remove the 'hidden' class to show the elements
		$('#ll-tools-flashcard').empty().removeClass('hidden');

		// Create image container and image elements
		let imgContainer = $('<div>', { class: 'flashcard-image-container' });
		let img = $('<img>', {
			src: wordData.image,
			alt: wordData.title,
			class: 'quiz-image'
		}).appendTo(imgContainer);

		// Append image container to the flashcard
		$('#ll-tools-flashcard').append(imgContainer);

		// Create an audio element and attempt to play the audio
		let audioElement = $('<audio>', {
			src: wordData.audio,
			controls: true,
			style: 'display: none;' // Hide the audio controls
		}).appendTo('#ll-tools-flashcard');

		audioElement[0].play().catch(function(e) {
			console.error("Audio play failed:", e);
			// Handle any errors that occur during play
		});

		// Update the button text
		$('#ll-tools-start-flashcard').text('Show Next Word');
	}

	
    // Function to show a quiz with 3 random images and play audio from one of them
    function showQuiz() {
		if (wordsData.length < 9) {
			alert("Not enough words for a quiz.");
			return;
		}

		let selectedWords = []; // To hold the selected words with their metadata
		let attempts = 0;

		while(selectedWords.length < 3 && attempts < 100) { // Limit attempts to avoid potential infinite loop
			let r = Math.floor(Math.random() * wordsData.length);
			let candidateWord = wordsData[r];
			
			let isSimilarWordSelected = false;
			selectedWords.forEach(selectedWord => {
				// Check if the current selected word has similar_word_id set and it matches the candidate's id
				if (selectedWord.similar_word_id && selectedWord.similar_word_id === candidateWord.id.toString()) {
					isSimilarWordSelected = true;
				}

				// Check if the candidate word has similar_word_id set and it matches the current selected word's id
				if (candidateWord.similar_word_id && candidateWord.similar_word_id === selectedWord.id.toString()) {
					isSimilarWordSelected = true;
				}
			});



			// If the word doesn't have a similar_word_id or it's not already selected, we can use it
			if (!isSimilarWordSelected && usedIndexes.indexOf(r) === -1) {
				selectedWords.push(candidateWord);
				usedIndexes.push(r); // Track used words to avoid repetition in future iterations
			}

			attempts++; // Increment the attempts counter
		}

		// If we couldn't find enough unique words
		if (selectedWords.length < 3) {
			alert("Could not find enough unique words for the quiz.");
			return;
		}

        // Update the usedIndexes with the new set of indexes
        usedIndexes = usedIndexes.concat(selectedWords).slice(-9); // Keep only the last 9 indexes to avoid repeats in immediate next sets

        // Randomly select one of the selected words to be the target
		let targetWord = selectedWords[Math.floor(Math.random() * selectedWords.length)];

        // Clear existing content
        $('#ll-tools-flashcard').empty();

		// Add images to the flashcard container
		selectedWords.forEach(wordData => {  // Use 'wordData' directly
			let imgContainer = $('<div>', { class: 'flashcard-image-container' });
			let img = $('<img>', {
				src: wordData.image,  // 'wordData' is already the word object
				alt: wordData.title,
				class: 'quiz-image'
			}).appendTo(imgContainer);

			imgContainer.click(function() {
				// Check if the clicked image is the correct answer
				if(wordData.title === targetWord.title) {
					playFeedback(true);
					// Proceed to the next quiz question
					showQuiz();
				} else {
					playFeedback(false);
				}
			});

			$('#ll-tools-flashcard').append(imgContainer);
		});

		// Play the audio of the target word
		$('<audio>', {
			src: targetWord.audio,
			controls: true
		}).appendTo('#ll-tools-flashcard')[0].play()
		.catch(function(e) {
			console.error("Audio play failed:", e);
		});

		$('#ll-tools-flashcard').removeClass('hidden');
		$('#ll-tools-start-flashcard').addClass('hidden');
    }


    // Decide which function to call based on the mode passed from PHP
    function initFlashcardWidget() {
        var mode = llToolsFlashcardsData.mode; // Access the mode
        if (mode === 'quiz') {
            showQuiz();
        } else {
            // Default to showRandomWord if mode is 'random' or not defined
            showRandomWord();
        }
    }

    // Event handler to start the widget
    $('#ll-tools-start-flashcard').on('click', function() {
    	// Initialize the flashcard widget
    	initFlashcardWidget();
    });

    // Event handler for clicking the flashcard cover
    $('#ll-tools-flashcard .ll-tools-flashcard-cover').on('click', function() {
        $(this).hide(); // Hide the cover
        // The image and audio should already be visible if they were not hidden again
        if ($('#ll-tools-flashcard audio').length) {
            $('#ll-tools-flashcard audio')[0].play();
        }
    });
});
