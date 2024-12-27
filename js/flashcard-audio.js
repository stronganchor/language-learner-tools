(function($) {
    // FlashcardAudio Module
    var FlashcardAudio = (function() {
        var activeAudios = [];
        var currentTargetAudio = null;
        var targetAudioHasPlayed = false;
        var correctAudio, wrongAudio;

        // Initialize our “correct” and “wrong” answer audio
        function initializeAudio() {
            correctAudio = new Audio(llToolsFlashcardsData.plugin_dir + './media/right-answer.mp3');
            wrongAudio = new Audio(llToolsFlashcardsData.plugin_dir + './media/wrong-answer.mp3');
        }

        /**
         * Play a given Audio object with error logging.
         */
        function playAudio(audio) {
            if (!audio) return;
            try {
                if (!audio.paused) {
                    audio.pause();
                    audio.currentTime = 0; // Reset playback
                }
                activeAudios.push(audio);
                audio.play().catch(function(e) {
                    console.error("Audio play failed for:", audio.src, e);
                });
            } catch (e) {
                console.error("Audio pause/play error for:", audio?.src, e);
            }
        }

        /**
         * Pause all active audio.
         */
        function pauseAllAudio() {
            activeAudios.forEach(function (audio) {
                try {
                    audio.pause();
                    audio.currentTime = 0;
                } catch (e) {
                    console.error("Audio pause error for:", audio?.src, e);
                }
            });
            activeAudios = [];
        }

        /**
         *  Plays either correct or wrong audio, optionally chaining to 
         *  the target word’s audio or a callback. 
         */
        function playFeedback(isCorrect, targetWordAudio, callback) {
            var audioToPlay = isCorrect ? correctAudio : wrongAudio;

            // Only allow this if the target word’s audio has played enough
            if (!targetAudioHasPlayed || (isCorrect && !audioToPlay.paused)) {
                return;
            }

            playAudio(audioToPlay);

            if (!isCorrect && targetWordAudio) {
                // After the “wrong” sound finishes, play the target word’s audio
                wrongAudio.onended = function () {
                    playAudio(currentTargetAudio);
                };
            } else if (isCorrect && typeof callback === 'function') {
                // If correct, run the callback after finishing
                correctAudio.onended = callback;
            }
        }

        /**
         *  Sets up the target word’s audio by creating a new <audio> element
         *  in the #ll-tools-flashcard container, then playing it.
         */
        function setTargetWordAudio(targetWord) {
            // Remove any old audio elements from prior rounds
            $('#ll-tools-flashcard audio').remove();

            // Create an <audio> element for this word
            let audioElement = $('<audio>', {
                src: targetWord.audio,
                controls: true
            }).appendTo('#ll-tools-flashcard'); 

            currentTargetAudio = audioElement[0];
            playAudio(currentTargetAudio);

            // Once we’re ~0.4s in, let the user interact
            currentTargetAudio.addEventListener('timeupdate', function timeUpdateListener() {
                if (this.currentTime > 0.4 || this.ended) {
                    targetAudioHasPlayed = true;
                    this.removeEventListener('timeupdate', timeUpdateListener);
                }
            });

            // Log if there's an error loading/playing this audio
            currentTargetAudio.onerror = function(e) {
                console.error("Error playing target audio file:", currentTargetAudio.src, e);
            };
        }

        /**
         *  Reset everything for the next quiz, etc.
         */
        function resetAudioState() {
            pauseAllAudio();
            activeAudios = [];
            currentTargetAudio = null;
            targetAudioHasPlayed = false;
        }

        // Expose just the needed methods
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
            getCorrectAudioURL: function() { return correctAudio.src; },
            getWrongAudioURL: function() { return wrongAudio.src; }
        };
    })();

    // Expose FlashcardAudio globally
    window.FlashcardAudio = FlashcardAudio;
})(jQuery);
