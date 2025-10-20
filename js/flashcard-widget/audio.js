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
        var autoplayBlocked = false;
        var allAudioElements = []; // Track ALL audio elements created anywhere

        /**
         * Initializes the correct and wrong answer audio files.
         */
        function initializeAudio() {
            correctAudio = new Audio(llToolsFlashcardsData.plugin_dir + './media/right-answer.mp3');
            wrongAudio = new Audio(llToolsFlashcardsData.plugin_dir + './media/wrong-answer.mp3');
            allAudioElements.push(correctAudio, wrongAudio);
        }

        /**
         * Register an audio element for tracking
         */
        function registerAudio(audio) {
            if (audio && !allAudioElements.includes(audio)) {
                allAudioElements.push(audio);
            }
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
                registerAudio(audio);
                audio.play().catch(function (e) {
                    console.error("Audio play failed for:", audio.src, e);

                    // Check if it's an autoplay NotAllowedError
                    if (e.name === 'NotAllowedError' && !autoplayBlocked) {
                        autoplayBlocked = true;
                        // Emit event for UI to handle
                        if (window.LLFlashcards && window.LLFlashcards.Dom) {
                            window.LLFlashcards.Dom.showAutoplayBlockedOverlay();
                        }
                    }
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
         * NUCLEAR OPTION: Stop and cleanup ALL audio elements everywhere
         * BUT preserve the feedback sounds (correct/wrong)
         */
        function killAllAudio() {
            console.log('killAllAudio called, cleaning up', allAudioElements.length, 'audio elements');

            // Stop all tracked audio EXCEPT feedback sounds
            allAudioElements.forEach(function (audio) {
                if (!audio) return;
                // Skip the feedback sounds - we need those
                if (audio === correctAudio || audio === wrongAudio) return;

                try {
                    audio.pause();
                    audio.currentTime = 0;
                    audio.onended = null;
                    audio.onerror = null;
                    audio.ontimeupdate = null;
                    audio.onloadstart = null;
                    audio.oncanplaythrough = null;
                    audio.removeAttribute('src');
                    audio.load();
                } catch (e) {
                    // Ignore errors during cleanup
                }
            });

            // Keep only feedback sounds in tracking
            allAudioElements = [correctAudio, wrongAudio].filter(Boolean);
            activeAudios = [];

            // Clean up current target audio reference
            if (currentTargetAudio && currentTargetAudio !== correctAudio && currentTargetAudio !== wrongAudio) {
                try {
                    currentTargetAudio.pause();
                    currentTargetAudio.currentTime = 0;
                    currentTargetAudio.onended = null;
                    currentTargetAudio.onerror = null;
                    currentTargetAudio.ontimeupdate = null;
                } catch (e) { }
            }

            // Remove any audio elements from DOM
            try {
                jQuery('#ll-tools-flashcard audio').each(function () {
                    try {
                        this.pause();
                        this.currentTime = 0;
                        this.onended = null;
                        this.onerror = null;
                    } catch (e) { }
                }).remove();

                jQuery('#ll-tools-flashcard-content audio').each(function () {
                    try {
                        this.pause();
                        this.currentTime = 0;
                        this.onended = null;
                        this.onerror = null;
                    } catch (e) { }
                }).remove();

                jQuery('#ll-tools-flashcard-popup audio').each(function () {
                    try {
                        this.pause();
                        this.currentTime = 0;
                        this.onended = null;
                        this.onerror = null;
                    } catch (e) { }
                }).remove();
            } catch (e) {
                console.error('Error removing audio elements:', e);
            }

            currentTargetAudio = null;
            targetAudioHasPlayed = false;
        }

        /**
         * Plays feedback audio based on whether the answer is correct or wrong.
         * Optionally chains to the target word's audio or executes a callback after playback.
         *
         * @param {boolean} isCorrect - Indicates if the answer was correct.
         * @param {string} targetWordAudio - URL of the target word's audio file.
         * @param {Function} callback - Function to execute after correct audio playback.
         */
        function playFeedback(isCorrect, targetWordAudio, callback) {
            var audioToPlay = isCorrect ? correctAudio : wrongAudio;

            // Proceed only if the target word's audio has played sufficiently
            if (!targetAudioHasPlayed || (isCorrect && !audioToPlay.paused)) {
                return;
            }

            playAudio(audioToPlay);

            if (!isCorrect && targetWordAudio) {
                // After the "wrong" sound finishes, play the target word's audio
                wrongAudio.onended = function () {
                    playAudio(currentTargetAudio);
                };
            } else if (isCorrect && typeof callback === 'function') {
                // If correct, execute the callback after the correct sound finishes
                correctAudio.onended = callback;
            }
        }

        /**
         * Sets up and plays the target word's audio by creating a new <audio> element.
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
            registerAudio(currentTargetAudio);
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
            killAllAudio();
        }

        /**
         * Selects the best audio for a word based on recording type priority.
         * Priority: introduction > isolation > question > any available
         *
         * @param {Object} word - The word object with audio_files array
         * @param {Array} preferredTypes - Array of recording types in priority order
         * @returns {string|null} - URL of the best audio file
         */
        function selectBestAudio(word, preferredTypes) {
            if (!word || !word.audio_files || !Array.isArray(word.audio_files) || word.audio_files.length === 0) {
                return word.audio || null;
            }

            // Try each preferred type in order
            for (let type of preferredTypes) {
                const audioFile = word.audio_files.find(af => af.recording_type === type);
                if (audioFile && audioFile.url) {
                    return audioFile.url;
                }
            }

            // Fallback to first available audio
            return word.audio_files[0].url || word.audio || null;
        }

        /**
         * Clears the autoplay blocked flag (used after user interaction)
         */
        function clearAutoplayBlock() {
            autoplayBlocked = false;
        }

        // Expose public methods
        return {
            initializeAudio: initializeAudio,
            playAudio: playAudio,
            pauseAllAudio: pauseAllAudio,
            playFeedback: playFeedback,
            setTargetWordAudio: setTargetWordAudio,
            resetAudioState: resetAudioState,
            killAllAudio: killAllAudio,
            registerAudio: registerAudio,
            getCurrentTargetAudio: function () { return currentTargetAudio; },
            getTargetAudioHasPlayed: function () { return targetAudioHasPlayed; },
            setTargetAudioHasPlayed: function (value) { targetAudioHasPlayed = value; },
            getCorrectAudioURL: function () { return correctAudio.src; },
            getWrongAudioURL: function () { return wrongAudio.src; },
            selectBestAudio: selectBestAudio,
            clearAutoplayBlock: clearAutoplayBlock
        };
    })();

    // Expose the FlashcardAudio module globally
    window.FlashcardAudio = FlashcardAudio;
})(jQuery);