/**
 * flashcard-audio.js
 *
 * Handles audio playback functionalities for flashcards, including correct and wrong answer feedback.
 */
(function ($) {
    /**
     * FlashcardAudio Module
     *
     * Manages audio playback for flashcards, including playing correct/wrong sounds and target word audio.
     */
    var FlashcardAudio = (function () {
        var activeAudios = [];
        var currentTargetAudio = null;
        var targetAudioHasPlayed = false;
        var correctAudio, wrongAudio;

        /**
         * Initializes the correct and wrong answer audio files.
         */
        function initializeAudio() {
            correctAudio = new Audio(llToolsFlashcardsData.plugin_dir + './media/right-answer.mp3');
            wrongAudio = new Audio(llToolsFlashcardsData.plugin_dir + './media/wrong-answer.mp3');
        }

        /**
         * Plays a given Audio object and handles any playback errors.
         *
         * @param {HTMLAudioElement} audio - The audio element to play.
         */
        function playAudio(audio) {
            if (!audio) return;
            try {
                if (!audio.paused) {
                    audio.pause();
                    audio.currentTime = 0; // Reset playback
                }
                activeAudios.push(audio);
                audio.play().catch(function (e) {
                    console.error("Audio play failed for:", audio.src, e);
                });
            } catch (e) {
                console.error("Audio pause/play error for:", audio?.src, e);
            }
        }

        /**
         * Pauses all currently active audio elements and resets their playback.
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
         * Plays feedback audio based on whether the answer is correct or wrong.
         * Optionally chains to the target word’s audio or executes a callback after playback.
         *
         * @param {boolean} isCorrect - Indicates if the answer was correct.
         * @param {string} targetWordAudio - URL of the target word's audio file.
         * @param {Function} callback - Function to execute after correct audio playback.
         */
        function playFeedback(isCorrect, targetWordAudio, callback) {
            var audioToPlay = isCorrect ? correctAudio : wrongAudio;

            // Proceed only if the target word’s audio has played sufficiently
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
                // If correct, execute the callback after the correct sound finishes
                correctAudio.onended = callback;
            }
        }

        /**
         * Sets up and plays the target word’s audio by creating a new <audio> element.
         *
         * @param {Object} targetWord - The target word object containing audio URL.
         */
        function setTargetWordAudio(targetWord) {
            // Remove any existing audio elements from previous rounds
            $('#ll-tools-flashcard audio').remove();

            // Create a new <audio> element for the target word
            var audioElement = $('<audio>', {
                src: targetWord.audio,
                controls: true
            }).appendTo('#ll-tools-flashcard');

            currentTargetAudio = audioElement[0];
            playAudio(currentTargetAudio);

            // Allow user interaction after approximately 0.4 seconds of playback
            currentTargetAudio.addEventListener('timeupdate', function timeUpdateListener() {
                if (this.currentTime > 0.4 || this.ended) {
                    targetAudioHasPlayed = true;
                    this.removeEventListener('timeupdate', timeUpdateListener);
                }
            });

            // Log any errors encountered during audio playback
            currentTargetAudio.onerror = function (e) {
                console.error("Error playing target audio file:", currentTargetAudio.src, e);
            };
        }

        /**
         * Resets the audio state so that the quiz can be restarted.
         */
        function resetAudioState() {
            pauseAllAudio();
            activeAudios = [];
            currentTargetAudio = null;
            targetAudioHasPlayed = false;
        }

        // Expose public methods
        return {
            initializeAudio: initializeAudio,
            playAudio: playAudio,
            pauseAllAudio: pauseAllAudio,
            playFeedback: playFeedback,
            setTargetWordAudio: setTargetWordAudio,
            resetAudioState: resetAudioState,
            getCurrentTargetAudio: function () { return currentTargetAudio; },
            getTargetAudioHasPlayed: function () { return targetAudioHasPlayed; },
            setTargetAudioHasPlayed: function (value) { targetAudioHasPlayed = value; },
            getCorrectAudioURL: function () { return correctAudio.src; },
            getWrongAudioURL: function () { return wrongAudio.src; }
        };
    })();

    // Expose the FlashcardAudio module globally
    window.FlashcardAudio = FlashcardAudio;
})(jQuery);