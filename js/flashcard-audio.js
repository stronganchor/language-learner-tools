(function($) {
    // FlashcardAudio Module
    var FlashcardAudio = (function() {
        var activeAudios = [];
        var currentTargetAudio = null;
        var targetAudioHasPlayed = false;
        var correctAudio, wrongAudio;

        // Load audio feedback elements
        function initializeAudio() {
            correctAudio = new Audio(llToolsFlashcardsData.plugin_dir + './media/right-answer.mp3');
            wrongAudio = new Audio(llToolsFlashcardsData.plugin_dir + './media/wrong-answer.mp3');

            // Preload resources for correct and wrong answer sounds
            loadAudio(correctAudio.src);
            loadAudio(wrongAudio.src);
        }

        // Load an audio file so that it's cached for later use
        function loadAudio(audioURL) {
            if (!FlashcardAudio.loadedResources) {
                FlashcardAudio.loadedResources = {};
            }
            if (!FlashcardAudio.loadedResources[audioURL] && audioURL) {
                new Promise((resolve, reject) => {
                    let audio = new Audio(audioURL);
                    audio.oncanplaythrough = function() {
                        resolve(audio);
                    };
                    audio.onerror = function() {
                        reject(new Error('Audio load failed'));
                    };
                });
                FlashcardAudio.loadedResources[audioURL] = true;
            }
        }

        // Helper function for playing audio
        function playAudio(audio) {
            try {
                if (!audio.paused) {
                    audio.pause();
                    audio.currentTime = 0;
                }
            } catch (e) {
                console.error("Audio pause failed:", e);
            }

            activeAudios.push(audio);
            audio.play().catch(function (e) {
                console.error("Audio play failed:", e);
            });
        }

        // Pause all audio elements
        function pauseAllAudio() {
            activeAudios.forEach(function (audio) {
                try {
                    audio.pause();
                    audio.currentTime = 0; // Reset the playback position
                } catch (e) {
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
                wrongAudio.onended = function () {
                    playAudio(currentTargetAudio);
                };
            } else if (isCorrect && typeof callback === 'function') {
                // When the correct answer sound finishes, execute the callback
                correctAudio.onended = callback;
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

            // Update the currentTargetAudio variable
            currentTargetAudio = audioElement[0];

            // Begin playback and manage interaction based on playback status
            playAudio(currentTargetAudio); // Play the target word's audio

            // Prevent clicking (interaction) before the audio has played for a certain time
            currentTargetAudio.addEventListener('timeupdate', function timeUpdateListener() {

                // If the loading animation is still visible, hide it
                if ((this.currentTime > 0.1 || this.ended) && $('#ll-tools-loading-animation').is(':visible')) {
                    if (typeof window.hideLoadingAnimation === 'function') {
                        window.hideLoadingAnimation();
                    }
                }

                if (this.currentTime > 0.4 || this.ended) {
                    targetAudioHasPlayed = true; // Allow interactions with quiz options
                    // Remove the event listener
                    this.removeEventListener('timeupdate', timeUpdateListener);
                }
            });

            // Adjust visibility and state of elements based on audio playback
            currentTargetAudio.onended = function () {
                // Additional logic upon ending audio can be added here if necessary
            };
        }

        function resetAudioState() {
            pauseAllAudio();
            activeAudios = [];
            currentTargetAudio = null;
            targetAudioHasPlayed = false;
        }

        // Expose the methods and variables as needed
        return {
            initializeAudio: initializeAudio,
            playAudio: playAudio,
            pauseAllAudio: pauseAllAudio,
            playFeedback: playFeedback,
            setTargetWordAudio: setTargetWordAudio,
            resetAudioState: resetAudioState,
            getCurrentTargetAudio: function() { return currentTargetAudio; },
            getTargetAudioHasPlayed: function() { return targetAudioHasPlayed; },
            setTargetAudioHasPlayed: function(value) { targetAudioHasPlayed = value; },
            loadAudio: loadAudio
        };
    })();

    // Expose FlashcardAudio globally
    window.FlashcardAudio = FlashcardAudio;

})(jQuery);
