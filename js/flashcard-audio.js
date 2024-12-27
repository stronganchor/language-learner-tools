(function($) {
    // FlashcardAudio Module
    var FlashcardAudio = (function() {
        var activeAudios = [];
        var currentTargetAudio = null;
        var targetAudioHasPlayed = false;
        var correctAudio, wrongAudio;

        // Keep track of resources so we don't re-attempt loading the same file infinitely
        if (!FlashcardAudio.loadedResources) {
            FlashcardAudio.loadedResources = {};
        }

        // Load audio feedback elements
        function initializeAudio() {
            correctAudio = new Audio(llToolsFlashcardsData.plugin_dir + './media/right-answer.mp3');
            wrongAudio = new Audio(llToolsFlashcardsData.plugin_dir + './media/wrong-answer.mp3');

            // Preload resources for correct and wrong answer sounds
            loadAudio(correctAudio.src);
            loadAudio(wrongAudio.src);
        }

        /**
         * Load an audio file so that it’s cached for later use.
         * Includes error logging that shows which file failed.
         */
        function loadAudio(audioURL) {
            // If we've already loaded (or at least tried loading) this audio, skip
            if (!audioURL || FlashcardAudio.loadedResources[audioURL]) {
                return;
            }

            FlashcardAudio.loadedResources[audioURL] = true;

            // Use a lightweight Promise-based approach
            new Promise((resolve, reject) => {
                let audio = new Audio(audioURL);

                // If the file is playable
                audio.oncanplaythrough = function() {
                    resolve(audioURL);
                };

                // If something goes wrong
                audio.onerror = function(e) {
                    reject(new Error('Audio load failed for: ' + audioURL));
                };
            })
            .catch(function(err) {
                // Log an error with the file name
                console.error(err);
            });
        }

        /**
         * Helper function for playing an audio object
         * with a console error if it fails to play.
         */
        function playAudio(audio) {
            try {
                if (!audio.paused) {
                    audio.pause();
                    audio.currentTime = 0; // Reset playback position
                }
            } catch (e) {
                console.error("Audio pause failed for:", audio.src, e);
            }

            activeAudios.push(audio);

            audio.play().catch(function(e) {
                console.error("Audio play failed for:", audio.src, e);
            });
        }

        /**
         * Pause all audio elements in our active list
         */
        function pauseAllAudio() {
            activeAudios.forEach(function (audio) {
                try {
                    audio.pause();
                    audio.currentTime = 0; // Reset the playback position
                } catch (e) {
                    console.error("Audio pause failed for:", audio.src, e);
                }
            });
            activeAudios = [];
        }

        /**
         * Play feedback sound (correct or wrong), then
         * optionally play targetWordAudio or run a callback.
         */
        function playFeedback(isCorrect, targetWordAudio, callback) {
            var audioToPlay = isCorrect ? correctAudio : wrongAudio;

            // Don’t register clicks for the first half second & don’t repeat the 'correct' sound
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

        /**
         * Sets the target word’s audio in the DOM,
         * and attempts to play it once loaded.
         */
        function setTargetWordAudio(targetWord) {
            // Remove any existing audio elements to prevent clutter
            $('#ll-tools-flashcard audio').remove();

            // Create the audio element for the target word
            let audioElement = $('<audio>', {
                src: targetWord.audio,
                controls: true
            }).appendTo('#ll-tools-flashcard'); // Append it to the container

            // Update the currentTargetAudio variable
            currentTargetAudio = audioElement[0];

            // Start playing the audio
            playAudio(currentTargetAudio);

            // Before it’s fully played, we set a small threshold after which the user can interact
            currentTargetAudio.addEventListener('timeupdate', function timeUpdateListener() {
                if (this.currentTime > 0.4 || this.ended) {
                    targetAudioHasPlayed = true; // Allow quiz options
                    this.removeEventListener('timeupdate', timeUpdateListener);
                }
            });

            // Also handle error logging for the target audio
            currentTargetAudio.onerror = function(e) {
                console.error("Error playing target audio file:", currentTargetAudio.src, e);
            };
        }

        /**
         * Reset audio to initial state
         */
        function resetAudioState() {
            pauseAllAudio();
            activeAudios = [];
            currentTargetAudio = null;
            targetAudioHasPlayed = false;
        }

        // Expose module methods
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
