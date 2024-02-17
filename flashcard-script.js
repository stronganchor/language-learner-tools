jQuery(document).ready(function($) {
    var currentIndex = 0;

    function showRandomWord() {
        if (llToolsFlashcardsData.length === 0) {
            alert("No words found.");
            return;
        }

        var randomIndex = Math.floor(Math.random() * llToolsFlashcardsData.length);
        var wordData = llToolsFlashcardsData[randomIndex];

		// Remove the 'hidden' class to show the elements
        $('#ll-tools-flashcard img')
            .attr('src', wordData.image)
            .removeClass('hidden'); // Remove 'hidden' class to display the image
        $('#ll-tools-flashcard audio')
            .attr('src', wordData.audio)

		// Try to play the audio
        var audioElement = $('#ll-tools-flashcard audio')[0];
        if (audioElement) {
            audioElement.play()
                .catch(function(e) {
                    console.error("Audio play failed:", e);
                    // Handle any errors that occur during play
                });
        }
		
        // Show the flashcard cover by default
        $('#ll-tools-flashcard .ll-tools-flashcard-cover').show();
    }

    // Event handler for the start button
    $('#ll-tools-start-flashcard').on('click', function() {
        showRandomWord();
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
