(function ($) {
    /**
     * FlashcardAudio Module - Centralized audio lifecycle management
     *
     * Manages audio sessions, playback, and cleanup with promise-based operations
     */
    var FlashcardAudio = (function () {
        // Session tracking
        var currentSession = 0;
        var activeAudioElements = new Map(); // Map<Audio, sessionId>
        var currentTargetAudio = null;
        var targetAudioHasPlayed = false;
        var correctAudio, wrongAudio;
        var autoplayBlocked = false;

        // Cleanup tracking
        var pendingCleanup = null;

        /**
         * Initialize feedback sounds (persist across sessions)
         */
        function initializeAudio() {
            correctAudio = new Audio(llToolsFlashcardsData.plugin_dir + './media/right-answer.mp3');
            wrongAudio = new Audio(llToolsFlashcardsData.plugin_dir + './media/wrong-answer.mp3');

            // Mark feedback audio with special session ID so they're never cleaned up
            correctAudio.__sessionId = -1;
            wrongAudio.__sessionId = -1;

            activeAudioElements.set(correctAudio, -1);
            activeAudioElements.set(wrongAudio, -1);
        }

        /**
         * Start a new audio session - invalidates all previous audio
         * Returns a promise that resolves when cleanup is complete
         */
        function startNewSession() {
            var previousSession = currentSession;
            currentSession++;

            console.log('Audio: Starting session ' + currentSession + ' (was ' + previousSession + ')');

            return cleanupSession(previousSession).then(function () {
                console.log('Audio: Session ' + currentSession + ' ready');
            });
        }

        /**
         * Create a new audio element tied to current session
         */
        function createAudio(url, options) {
            options = options || {};

            if (!url) {
                console.warn('Audio: Cannot create audio without URL');
                return null;
            }

            var audio = new Audio(url);
            audio.__sessionId = currentSession;
            audio.__options = options;

            // Register it
            activeAudioElements.set(audio, currentSession);

            // Auto-cleanup on error
            audio.addEventListener('error', function onError() {
                console.error('Audio: Error for', url);
                cleanupSingleAudio(audio);
            }, { once: true });

            return audio;
        }

        /**
         * Check if audio belongs to current session
         */
        function isCurrentSession(audio) {
            if (!audio) return false;
            if (audio.__sessionId === -1) return true; // Feedback audio always valid
            return audio.__sessionId === currentSession;
        }

        /**
         * Play audio with session validation + AbortError resilience
         */
        function playAudio(audio) {
            if (!audio) {
                console.warn('Audio: Cannot play null audio');
                return Promise.reject(new Error('No audio element'));
            }

            // Check session validity
            if (!isCurrentSession(audio)) {
                console.log('Audio: Ignoring play from old session');
                return Promise.resolve();
            }

            try {
                // Reset if already playing
                if (!audio.paused) {
                    audio.pause();
                    audio.currentTime = 0;
                }

                return audio.play().catch(function (e) {
                    // Browser-specific interruption when a late pause hits just after play()
                    if (e && e.name === 'AbortError') {
                        // Retry once after a short tick *if* we're still in the current session
                        return new Promise(function (r) { setTimeout(r, 80); }).then(function () {
                            if (!isCurrentSession(audio)) return;
                            return audio.play().catch(function () { /* swallow second abort */ });
                        });
                    }

                    if (e.name === 'NotAllowedError' && !autoplayBlocked) {
                        autoplayBlocked = true;
                        if (window.LLFlashcards && window.LLFlashcards.Dom) {
                            window.LLFlashcards.Dom.showAutoplayBlockedOverlay();
                        }
                    }
                    console.error('Audio: Play failed', e);
                    throw e;
                });
            } catch (e) {
                console.error('Audio: Play error', e);
                return Promise.reject(e);
            }
        }

        /**
         * Stop a specific audio element
         */
        function stopAudio(audio) {
            if (!audio) return Promise.resolve();

            return new Promise(function (resolve) {
                try {
                    audio.pause();
                    audio.currentTime = 0;
                    resolve();
                } catch (e) {
                    console.error('Audio: Stop error', e);
                    resolve(); // Don't reject
                }
            });
        }

        /**
         * Stop all audio for a given sessionId (defaults to current if not provided)
         */
        function pauseAllAudio(sessionIdParam) {
            var targetSession = (typeof sessionIdParam === 'number') ? sessionIdParam : currentSession;
            var promises = [];

            activeAudioElements.forEach(function (sessionId, audio) {
                if (sessionId === targetSession) {
                    promises.push(stopAudio(audio));
                }
            });

            return Promise.all(promises);
        }

        /**
         * Cleanup a single audio element
         */
        function cleanupSingleAudio(audio) {
            if (!audio) return Promise.resolve();

            return new Promise(function (resolve) {
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
                    activeAudioElements.delete(audio);
                    resolve();
                } catch (e) {
                    console.error('Audio: Cleanup error', e);
                    resolve();
                }
            });
        }

        /**
         * Cleanup all audio from a specific session (or older)
         */
        function cleanupSession(sessionId) {
            // If cleanup already in progress, chain after it
            if (pendingCleanup) {
                return pendingCleanup.then(function () {
                    return cleanupSession(sessionId);
                });
            }

            console.log('Audio: Cleaning up session ' + sessionId);

            var promises = [];
            var toRemove = [];

            // Collect audio to cleanup (skip feedback audio with sessionId -1)
            activeAudioElements.forEach(function (audioSessionId, audio) {
                if (audioSessionId !== -1 && audioSessionId <= sessionId) {
                    promises.push(cleanupSingleAudio(audio));
                    toRemove.push(audio);
                }
            });

            // Cleanup DOM audio elements
            promises.push(new Promise(function (resolve) {
                try {
                    $('#ll-tools-flashcard audio, #ll-tools-flashcard-content audio, #ll-tools-flashcard-popup audio')
                        .each(function () {
                            try {
                                this.pause();
                                this.currentTime = 0;
                                this.onended = null;
                                this.onerror = null;
                            } catch (e) {
                                // Ignore
                            }
                        })
                        .remove();
                    resolve();
                } catch (e) {
                    console.error('Audio: DOM cleanup error', e);
                    resolve();
                }
            }));

            pendingCleanup = Promise.all(promises).then(function () {
                toRemove.forEach(function (audio) {
                    activeAudioElements.delete(audio);
                });

                if (currentTargetAudio && !isCurrentSession(currentTargetAudio)) {
                    currentTargetAudio = null;
                    targetAudioHasPlayed = false;
                }

                pendingCleanup = null;
                console.log('Audio: Cleanup complete for session ' + sessionId);
            });

            return pendingCleanup;
        }

        /**
         * Set target word audio (for quiz questions)
         */
        function setTargetWordAudio(targetWord) {
            if (!targetWord || !targetWord.audio) {
                console.warn('Audio: No audio for word');
                return Promise.reject(new Error('No audio'));
            }

            // Remove old target audio from DOM
            $('#ll-tools-flashcard audio').remove();

            // Create new audio in DOM
            var audioElement = $('<audio>', {
                src: targetWord.audio,
                controls: true,
                crossorigin: 'anonymous'
            }).appendTo('#ll-tools-flashcard');

            currentTargetAudio = audioElement[0];
            currentTargetAudio.__sessionId = currentSession;
            activeAudioElements.set(currentTargetAudio, currentSession);

            targetAudioHasPlayed = false;

            // Track when audio has played enough
            currentTargetAudio.addEventListener('timeupdate', function onTimeUpdate() {
                if (this.currentTime > 0.4 || this.ended) {
                    targetAudioHasPlayed = true;
                    this.removeEventListener('timeupdate', onTimeUpdate);
                }
            });

            currentTargetAudio.onerror = function (e) {
                console.error("Error playing target audio file:", currentTargetAudio.src, e);
            };

            return playAudio(currentTargetAudio);
        }

        /**
         * Play feedback sound (correct/wrong)
         */
        function playFeedback(isCorrect, targetWordAudio, callback) {
            var audioToPlay = isCorrect ? correctAudio : wrongAudio;

            if (!targetAudioHasPlayed || (isCorrect && !audioToPlay.paused)) {
                return Promise.resolve();
            }

            if (!isCorrect && targetWordAudio) {
                // Wrong answer: play wrong sound, then target audio
                wrongAudio.onended = function () {
                    playAudio(currentTargetAudio);
                };
                return playAudio(audioToPlay);
            } else if (isCorrect && typeof callback === 'function') {
                // Correct answer: play correct sound, then callback
                correctAudio.onended = callback;
                return playAudio(audioToPlay);
            } else {
                return playAudio(audioToPlay);
            }
        }

        /**
         * Create managed audio for introduction sequences
         * Returns an object with play/stop/cleanup methods
         */
        function createIntroductionAudio(url) {
            var audio = createAudio(url, { type: 'introduction' });

            return {
                audio: audio,
                play: function () {
                    return playAudio(audio);
                },
                stop: function () {
                    return stopAudio(audio);
                },
                cleanup: function () {
                    return cleanupSingleAudio(audio);
                },
                isValid: function () {
                    return isCurrentSession(audio);
                },
                // Play until audio ends (includes watchdog timer)
                playUntilEnd: function () {
                    return new Promise(function (resolve, reject) {
                        if (!isCurrentSession(audio)) {
                            resolve(); // Not an error, just outdated
                            return;
                        }

                        var resolved = false;
                        var watchdog = null;

                        function onEnd() {
                            if (resolved) return;
                            resolved = true;
                            if (watchdog) clearTimeout(watchdog);
                            audio.onended = null;
                            audio.onerror = null;
                            resolve();
                        }

                        audio.onended = onEnd;
                        audio.onerror = onEnd;

                        // Watchdog: force end after 15 seconds
                        watchdog = setTimeout(onEnd, 15000);

                        playAudio(audio).catch(function (err) {
                            if (!resolved) {
                                resolved = true;
                                if (watchdog) clearTimeout(watchdog);
                                reject(err);
                            }
                        });
                    });
                }
            };
        }

        /**
         * Select best audio for a word based on recording type priority
         */
        function selectBestAudio(word, preferredTypes) {
            if (!word || !word.audio_files || !Array.isArray(word.audio_files) || word.audio_files.length === 0) {
                return word.audio || null;
            }

            for (var i = 0; i < preferredTypes.length; i++) {
                var type = preferredTypes[i];
                var audioFile = word.audio_files.find(function (af) {
                    return af.recording_type === type;
                });
                if (audioFile && audioFile.url) {
                    return audioFile.url;
                }
            }

            return word.audio_files[0].url || word.audio || null;
        }

        /**
         * Clear autoplay block flag
         */
        function clearAutoplayBlock() {
            autoplayBlocked = false;
        }

        // Public API
        return {
            initializeAudio: initializeAudio,
            startNewSession: startNewSession,
            createAudio: createAudio,
            createIntroductionAudio: createIntroductionAudio,
            isCurrentSession: isCurrentSession,
            playAudio: playAudio,
            pauseAllAudio: pauseAllAudio,
            setTargetWordAudio: setTargetWordAudio,
            playFeedback: playFeedback,
            selectBestAudio: selectBestAudio,
            clearAutoplayBlock: clearAutoplayBlock,
            getCurrentSessionId: function () { return currentSession; },
            getCurrentTargetAudio: function () { return currentTargetAudio; },
            getTargetAudioHasPlayed: function () { return targetAudioHasPlayed; },
            setTargetAudioHasPlayed: function (value) { targetAudioHasPlayed = value; },
            getCorrectAudioURL: function () { return correctAudio ? correctAudio.src : ''; },
            getWrongAudioURL: function () { return wrongAudio ? wrongAudio.src : ''; }
        };
    })();

    // Expose globally
    window.FlashcardAudio = FlashcardAudio;
})(jQuery);
