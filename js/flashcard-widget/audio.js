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
        var playbackSuspended = false;

        // Cleanup tracking
        var pendingCleanup = null;

        function shouldLog() {
            return !!(window.llToolsFlashcardsData && window.llToolsFlashcardsData.debug);
        }

        function log() {
            if (!shouldLog() || !window.console || typeof window.console.log !== 'function') return;
            window.console.log.apply(window.console, arguments);
        }

        function warn() {
            if (!shouldLog() || !window.console || typeof window.console.warn !== 'function') return;
            window.console.warn.apply(window.console, arguments);
        }

        function error() {
            if (!shouldLog() || !window.console || typeof window.console.error !== 'function') return;
            window.console.error.apply(window.console, arguments);
        }

        /**
         * Normalize a media URL to the current page origin/protocol when possible.
         * Prevents CORS errors when assets are the same host but a different scheme.
         */
        function normalizeUrlToPageOrigin(url) {
            if (!url || typeof url !== 'string') return url;
            try {
                var hasProtocol = /^[a-zA-Z][a-zA-Z\d+\-.]*:/.test(url);
                var isProtocolRelative = url.indexOf('//') === 0;
                var loc = (typeof window !== 'undefined' && window.location) ? window.location : null;
                if (!loc) return url;

                // Promote protocol-relative to current protocol
                if (isProtocolRelative) {
                    return loc.protocol + url;
                }

                // Build URL using page as base when relative or missing protocol
                var base = new URL(loc.href);
                var parsed = new URL(url, hasProtocol ? undefined : base);

                var sameHost = parsed.hostname === base.hostname;
                if (sameHost) {
                    if (parsed.protocol !== base.protocol) {
                        parsed.protocol = base.protocol;
                    }
                    if (!parsed.port && base.port) {
                        parsed.port = base.port;
                    }
                }

                return parsed.toString();
            } catch (e) {
                return url;
            }
        }

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
            playbackSuspended = false;

            log('Audio: Starting session ' + currentSession + ' (was ' + previousSession + ')');

            return cleanupSession(previousSession).then(function () {
                log('Audio: Session ' + currentSession + ' ready');
            });
        }

        /**
         * Create a new audio element tied to current session
         */
        function createAudio(url, options) {
            options = options || {};

            if (!url) {
                warn('Audio: Cannot create audio without URL');
                return null;
            }

            var resolvedUrl = normalizeUrlToPageOrigin(url);
            var audio = document.createElement('audio');
            // Required for analyser/visualizer usage on cross-origin audio (B2, CDN, etc.).
            audio.crossOrigin = 'anonymous';
            audio.src = resolvedUrl;
            audio.__sessionId = currentSession;
            audio.__options = options;

            // Register it
            activeAudioElements.set(audio, currentSession);

            // Auto-cleanup on error
            audio.addEventListener('error', function onError() {
                error('Audio: Error for', resolvedUrl);
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
                warn('Audio: Cannot play null audio');
                return Promise.reject(new Error('No audio element'));
            }

            if (playbackSuspended) {
                log('Audio: Playback suspended, skipping play request');
                return stopAudio(audio).then(function () { return; });
            }

            // Check session validity
            if (!isCurrentSession(audio)) {
                log('Audio: Ignoring play from old session');
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
                    error('Audio: Play failed', e);
                    throw e;
                });
            } catch (e) {
                error('Audio: Play error', e);
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
                    if (typeof audio.pause === 'function') {
                        audio.pause();
                    }
                    if (typeof audio.currentTime === 'number') {
                        audio.currentTime = 0;
                    }
                    // Prevent any deferred callbacks from firing after we've stopped it
                    audio.onended = null;
                    audio.onerror = null;
                    resolve();
                } catch (e) {
                    error('Audio: Stop error', e);
                    resolve(); // Don't reject
                }
            });
        }

        /**
         * Fade out a specific audio element before stopping it
         */
        function fadeOutAudio(audio, durationMs) {
            return new Promise(function (resolve) {
                if (!audio) { resolve(); return; }

                var startingVolume = (typeof audio.volume === 'number') ? audio.volume : 1;
                var resetVolume = function () {
                    try { audio.volume = startingVolume; } catch (_) { /* no-op */ }
                };
                var fadeDuration = Math.max(60, (typeof durationMs === 'number') ? durationMs : 180);

                if (audio.paused || startingVolume <= 0) {
                    stopAudio(audio).then(function () {
                        resetVolume();
                        resolve();
                    });
                    return;
                }

                var steps = Math.max(3, Math.round(fadeDuration / 30));
                var stepMs = Math.max(16, fadeDuration / steps);
                var decrement = startingVolume / steps;
                var stepCount = 0;
                var intervalId = setInterval(function () {
                    stepCount++;
                    var nextVolume = Math.max(0, startingVolume - (decrement * stepCount));
                    try { audio.volume = nextVolume; } catch (_) { /* ignore */ }
                    if (stepCount >= steps || nextVolume <= 0.01) {
                        clearInterval(intervalId);
                        stopAudio(audio).then(function () {
                            resetVolume();
                            resolve();
                        });
                    }
                }, stepMs);
            });
        }

        /**
         * Fade out all active audio (default excludes feedback sounds)
         */
        function fadeOutAllAudio(durationMs, includeFeedbackAudio) {
            var fades = [];
            activeAudioElements.forEach(function (sessionId, audio) {
                if (!audio || audio.paused) return;
                if (!includeFeedbackAudio && sessionId === -1) return;
                fades.push(fadeOutAudio(audio, durationMs));
            });
            if (!fades.length) return Promise.resolve();
            return Promise.all(fades.map(function (p) { return Promise.resolve(p).catch(function () { return; }); }))
                .then(function () { return; });
        }

        /**
         * Fade out feedback audio (ding/buzz) early
         */
        function fadeOutFeedbackAudio(durationMs, which) {
            var targets = [];
            if (!which || which === 'correct' || which === 'both') targets.push(correctAudio);
            if (!which || which === 'wrong' || which === 'both') targets.push(wrongAudio);
            var fades = targets.filter(Boolean).map(function (audio) {
                return fadeOutAudio(audio, durationMs);
            });
            if (!fades.length) return Promise.resolve();
            return Promise.all(fades.map(function (p) { return Promise.resolve(p).catch(function () { return; }); }))
                .then(function () { return; });
        }

        /**
         * Force stop and cleanup every tracked audio instance, including target audio.
         */
        function flushAllAudioSessions() {
            var tasks = [];

            activeAudioElements.forEach(function (sessionId, audio) {
                // Always stop the audio first
                tasks.push(stopAudio(audio));

                // Feedback audio is session -1 and should persist; skip removing it
                if (audio && audio.__sessionId !== -1 && audio !== currentTargetAudio) {
                    tasks.push(cleanupSingleAudio(audio));
                }
            });

            if (currentTargetAudio && currentTargetAudio.__sessionId !== -1) {
                tasks.push(cleanupSingleAudio(currentTargetAudio).then(function () {
                    currentTargetAudio = null;
                    targetAudioHasPlayed = false;
                }));
            } else {
                currentTargetAudio = null;
                targetAudioHasPlayed = false;
            }

            try { if (correctAudio) { correctAudio.onended = null; correctAudio.onerror = null; } } catch (_) { }
            try { if (wrongAudio) { wrongAudio.onended = null; wrongAudio.onerror = null; } } catch (_) { }
            try { $('#ll-tools-flashcard audio').remove(); } catch (_) { }

            autoplayBlocked = false;

            return Promise.all(tasks.map(function (p) { return Promise.resolve(p).catch(function () { return; }); }))
                .then(function () { return; });
        }

        /**
         * Suspend playback immediately to block late/racing plays during close/switch.
         */
        function suspendPlayback() {
            playbackSuspended = true;
            var tasks = [
                pauseAllAudio(),
                pauseAllAudio(-1)
            ];
            return Promise.all(tasks.map(function (p) {
                return Promise.resolve(p).catch(function () { return; });
            })).then(function () { return; });
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
                    error('Audio: Cleanup error', e);
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

            log('Audio: Cleaning up session ' + sessionId);

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
                    error('Audio: DOM cleanup error', e);
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
                log('Audio: Cleanup complete for session ' + sessionId);
            });

            return pendingCleanup;
        }

        /**
         * Set target word audio (for quiz questions)
         */
        function setTargetWordAudio(targetWord) {
            // Always clear any previous target audio to avoid stale playback
            try {
                if (currentTargetAudio) {
                    // Fully cleanup the previously tracked target audio element
                    cleanupSingleAudio(currentTargetAudio);
                }
                // Also remove any stray <audio> tags in the flashcard container
                $('#ll-tools-flashcard audio').remove();
            } catch (_) { /* swallow */ }

            // If no audio exists for this word, mark as satisfied so UI isn't blocked
            if (!targetWord || !targetWord.audio) {
                warn('Audio: No audio for word');
                currentTargetAudio = null;
                // Treat as "played" so feedback and interactions aren't gated
                targetAudioHasPlayed = true;
                return Promise.resolve();
            }

            if (playbackSuspended) {
                currentTargetAudio = null;
                targetAudioHasPlayed = true;
                return Promise.resolve();
            }

            var audioSrc = normalizeUrlToPageOrigin(targetWord.audio);

            // Create new audio in DOM
            var audioElement = $('<audio>', {
                src: audioSrc,
                controls: true,
                crossorigin: 'anonymous'
            }).appendTo('#ll-tools-flashcard');

            currentTargetAudio = audioElement[0];
            currentTargetAudio.__sessionId = currentSession;
            activeAudioElements.set(currentTargetAudio, currentSession);

            targetAudioHasPlayed = false;

            // Mark as satisfied once playback starts or ends
            currentTargetAudio.addEventListener('timeupdate', function onTimeUpdate() {
                if (this.currentTime > 0.4 || this.ended) {
                    targetAudioHasPlayed = true;
                    this.removeEventListener('timeupdate', onTimeUpdate);
                }
            });

            // If the file fails to load/play, don't block interactions
            currentTargetAudio.onerror = function (e) {
                try { error('Error playing target audio file:', currentTargetAudio && currentTargetAudio.src, e); } catch (_) { }
                // Unblock any feedback that waits for target audio
                targetAudioHasPlayed = true;
            };

            var p = playAudio(currentTargetAudio);
            if (p && typeof p.catch === 'function') {
                // Swallow playback errors so the round can proceed
                return p.catch(function () { return; });
            }
            return Promise.resolve();
        }

        /**
         * Play feedback sound (correct/wrong)
         */
        function playFeedback(isCorrect, targetWordAudio, callback) {
            var audioToPlay = isCorrect ? correctAudio : wrongAudio;

            if (playbackSuspended) {
                if (typeof callback === 'function') {
                    try { callback(); } catch (_) { /* no-op */ }
                }
                return Promise.resolve();
            }

            // If there's no target audio, treat the requirement as satisfied
            var playbackSatisfied = targetAudioHasPlayed || !currentTargetAudio;
            if (!playbackSatisfied) {
                return Promise.resolve();
            }

            if (!isCorrect) {
                // Wrong answer: play wrong sound, then target audio if available
                if (currentTargetAudio) {
                    wrongAudio.onended = function () { playAudio(currentTargetAudio); };
                } else if (typeof callback === 'function') {
                    // If no target audio, chain to callback if provided
                    wrongAudio.onended = callback;
                } else {
                    wrongAudio.onended = null;
                }
                return playAudio(audioToPlay);
            } else {
                // Correct answer: play correct sound, then callback if provided
                correctAudio.onended = (typeof callback === 'function') ? callback : null;
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
                        if (playbackSuspended) {
                            resolve();
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
                return normalizeUrlToPageOrigin(word.audio || null);
            }

            var preferredSpeaker = word.preferred_speaker_user_id || 0;

            for (var i = 0; i < preferredTypes.length; i++) {
                var type = preferredTypes[i];

                // First pass: match type AND preferred speaker if available
                if (preferredSpeaker) {
                    var matchSameSpeaker = word.audio_files.find(function (af) {
                        return af.recording_type === type && af.speaker_user_id === preferredSpeaker && af.url;
                    });
                    if (matchSameSpeaker) {
                        return normalizeUrlToPageOrigin(matchSameSpeaker.url);
                    }
                }

                // Fallback: any file matching type
                var anyMatch = word.audio_files.find(function (af) {
                    return af.recording_type === type && af.url;
                });
                if (anyMatch) {
                    return normalizeUrlToPageOrigin(anyMatch.url);
                }
            }

            var fallback = (word.audio_files[0] && word.audio_files[0].url) || word.audio || null;
            return normalizeUrlToPageOrigin(fallback);
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
            suspendPlayback: suspendPlayback,
            // Expose full-session flush so callers can clean everything
            flushAllAudioSessions: flushAllAudioSessions,
            setTargetWordAudio: setTargetWordAudio,
            playFeedback: playFeedback,
            selectBestAudio: selectBestAudio,
            clearAutoplayBlock: clearAutoplayBlock,
            getCurrentSessionId: function () { return currentSession; },
            getCurrentTargetAudio: function () { return currentTargetAudio; },
            getTargetAudioHasPlayed: function () { return targetAudioHasPlayed; },
            setTargetAudioHasPlayed: function (value) { targetAudioHasPlayed = value; },
            getCorrectAudioURL: function () { return correctAudio ? correctAudio.src : ''; },
            getWrongAudioURL: function () { return wrongAudio ? wrongAudio.src : ''; },
            fadeOutAllAudio: fadeOutAllAudio,
            fadeOutFeedbackAudio: fadeOutFeedbackAudio
        };
    })();

    // Expose globally
    window.FlashcardAudio = FlashcardAudio;
})(jQuery);
