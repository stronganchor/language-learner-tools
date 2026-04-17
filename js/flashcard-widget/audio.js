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
            correctAudio.__options = { type: 'feedback-correct', minReadyState: 2, readyTimeoutMs: 900 };
            wrongAudio.__options = { type: 'feedback-wrong', minReadyState: 2, readyTimeoutMs: 900 };
            try { correctAudio.preload = 'auto'; } catch (_) { /* no-op */ }
            try { wrongAudio.preload = 'auto'; } catch (_) { /* no-op */ }
            try { correctAudio.load(); } catch (_) { /* no-op */ }
            try { wrongAudio.load(); } catch (_) { /* no-op */ }

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
            audio.__sessionId = currentSession;
            audio.__options = options;
            try { audio.preload = 'auto'; } catch (_) { /* no-op */ }
            audio.src = resolvedUrl;

            // Register it
            activeAudioElements.set(audio, currentSession);

            // Auto-cleanup on error
            audio.addEventListener('error', function onError() {
                error('Audio: Error for', resolvedUrl);
                cleanupSingleAudio(audio);
            }, { once: true });

            return audio;
        }

        function getAudioDesiredReadyState(audio, overrideState) {
            var numeric = parseInt(overrideState, 10);
            if (numeric >= 4) { return 4; }
            if (numeric >= 3) { return 3; }
            if (numeric >= 2) { return 2; }

            var options = (audio && audio.__options && typeof audio.__options === 'object')
                ? audio.__options
                : {};
            var configured = parseInt(options.minReadyState, 10);
            if (configured >= 4) { return 4; }
            if (configured >= 3) { return 3; }
            if (configured >= 2) { return 2; }

            var type = String(options.type || '').trim().toLowerCase();
            if (type === 'target' || type === 'introduction') {
                return 3;
            }

            return 2;
        }

        function getAudioReadyTimeout(audio, overrideMs) {
            var numeric = parseInt(overrideMs, 10);
            if (numeric > 0) {
                return numeric;
            }

            var options = (audio && audio.__options && typeof audio.__options === 'object')
                ? audio.__options
                : {};
            var configured = parseInt(options.readyTimeoutMs, 10);
            if (configured > 0) {
                return configured;
            }

            var type = String(options.type || '').trim().toLowerCase();
            if (type === 'target' || type === 'introduction') {
                return 1800;
            }

            return 900;
        }

        function isAudioPlayable(audio, minReadyState) {
            if (!audio) {
                return false;
            }

            try {
                if (audio.error) {
                    return false;
                }
            } catch (_) { /* no-op */ }

            var desiredReadyState = getAudioDesiredReadyState(audio, minReadyState);
            var readyState = 0;

            try {
                readyState = (typeof audio.readyState === 'number') ? audio.readyState : 0;
            } catch (_) {
                readyState = 0;
            }

            return readyState >= desiredReadyState;
        }

        function waitForAudioPlayable(audio, options) {
            options = options || {};

            if (!audio) {
                return Promise.resolve(false);
            }

            var desiredReadyState = getAudioDesiredReadyState(audio, options.minReadyState);
            if (isAudioPlayable(audio, desiredReadyState)) {
                return Promise.resolve(true);
            }

            return new Promise(function (resolve) {
                var settled = false;
                var timeoutId = null;

                var checkReady = function () {
                    if (isAudioPlayable(audio, desiredReadyState)) {
                        finish(true);
                    }
                };

                var detach = function () {
                    try { audio.removeEventListener('canplaythrough', checkReady); } catch (_) { /* no-op */ }
                    try { audio.removeEventListener('canplay', checkReady); } catch (_) { /* no-op */ }
                    try { audio.removeEventListener('loadeddata', checkReady); } catch (_) { /* no-op */ }
                    try { audio.removeEventListener('loadedmetadata', checkReady); } catch (_) { /* no-op */ }
                    try { audio.removeEventListener('progress', checkReady); } catch (_) { /* no-op */ }
                    try { audio.removeEventListener('playing', checkReady); } catch (_) { /* no-op */ }
                    try { audio.removeEventListener('error', onFailure); } catch (_) { /* no-op */ }
                    try { audio.removeEventListener('abort', onFailure); } catch (_) { /* no-op */ }
                };

                var finish = function (ready) {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    if (timeoutId) {
                        clearTimeout(timeoutId);
                        timeoutId = null;
                    }
                    detach();
                    resolve(!!ready);
                };

                var onFailure = function () {
                    finish(false);
                };

                try { audio.addEventListener('canplaythrough', checkReady, { once: true }); } catch (_) { /* no-op */ }
                try { audio.addEventListener('canplay', checkReady, { once: true }); } catch (_) { /* no-op */ }
                try { audio.addEventListener('loadeddata', checkReady, { once: true }); } catch (_) { /* no-op */ }
                try { audio.addEventListener('loadedmetadata', checkReady, { once: true }); } catch (_) { /* no-op */ }
                try { audio.addEventListener('progress', checkReady, { passive: true }); } catch (_) { /* no-op */ }
                try { audio.addEventListener('playing', checkReady, { once: true }); } catch (_) { /* no-op */ }
                try { audio.addEventListener('error', onFailure, { once: true }); } catch (_) { /* no-op */ }
                try { audio.addEventListener('abort', onFailure, { once: true }); } catch (_) { /* no-op */ }

                timeoutId = setTimeout(function () {
                    var fallbackReady = false;
                    try {
                        fallbackReady = !!(audio && typeof audio.readyState === 'number' && audio.readyState >= 2 && !audio.error);
                    } catch (_) {
                        fallbackReady = false;
                    }
                    finish(fallbackReady);
                }, Math.max(250, getAudioReadyTimeout(audio, options.timeoutMs)));

                checkReady();
            });
        }

        /**
         * Check if audio belongs to current session
         */
        function isCurrentSession(audio) {
            if (!audio) return false;
            if (audio.__sessionId === -1) return true; // Feedback audio always valid
            return audio.__sessionId === currentSession;
        }

        function isAudioMutedForQuizGate(audio) {
            if (!audio) return false;
            try {
                if (audio.muted) {
                    return true;
                }
            } catch (_) { /* no-op */ }
            try {
                if (typeof audio.volume === 'number' && audio.volume <= 0.01) {
                    return true;
                }
            } catch (_) { /* no-op */ }
            return false;
        }

        function requestQuizSoundGate(audio, options) {
            var domApi = window.LLFlashcards && window.LLFlashcards.Dom;
            if (!domApi || typeof domApi.requestSoundGate !== 'function') {
                return false;
            }
            return !!domApi.requestSoundGate(audio, options || {});
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

            return waitForAudioPlayable(audio).catch(function () {
                return false;
            }).then(function () {
                if (playbackSuspended) {
                    log('Audio: Playback suspended after readiness wait, skipping play request');
                    return stopAudio(audio).then(function () { return; });
                }

                if (!isCurrentSession(audio)) {
                    log('Audio: Ignoring ready play from old session');
                    return;
                }

                try {
                    // Reset if already playing
                    if (!audio.paused) {
                        audio.pause();
                        audio.currentTime = 0;
                    }

                    if (isAudioMutedForQuizGate(audio) && requestQuizSoundGate(audio, { reason: 'muted', force: false })) {
                        return Promise.reject(new Error('Quiz audio is muted'));
                    }

                    return audio.play().catch(function (e) {
                        // Browser-specific interruption when a late pause hits just after play()
                        if (e && e.name === 'AbortError') {
                            // Retry once after a short tick *if* we're still in the current session
                            return new Promise(function (r) { setTimeout(r, 80); }).then(function () {
                                if (!isCurrentSession(audio)) return;
                                return waitForAudioPlayable(audio).catch(function () {
                                    return false;
                                }).then(function () {
                                    if (!isCurrentSession(audio)) return;
                                    return audio.play().catch(function () { /* swallow second abort */ });
                                });
                            });
                        }

                        if (e.name === 'NotAllowedError' && !autoplayBlocked) {
                            autoplayBlocked = true;
                            requestQuizSoundGate(audio, { reason: 'autoplay-blocked', force: false });
                        }
                        error('Audio: Play failed', e);
                        throw e;
                    });
                } catch (e) {
                    error('Audio: Play error', e);
                    return Promise.reject(e);
                }
            });
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
        function setTargetWordAudio(targetWord, options) {
            options = options || {};
            var shouldAutoplay = options.autoplay !== false;
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

            // Build with crossOrigin set before src so analyser access is reliable.
            var mount = document.getElementById('ll-tools-flashcard');
            var audioElement = document.createElement('audio');
            try { audioElement.controls = true; } catch (_) { /* no-op */ }
            try { audioElement.crossOrigin = 'anonymous'; } catch (_) { /* no-op */ }
            try { audioElement.preload = 'auto'; } catch (_) { /* no-op */ }
            audioElement.__options = { type: 'target', minReadyState: 3, readyTimeoutMs: 2200 };
            audioElement.src = audioSrc;
            if (mount) {
                mount.appendChild(audioElement);
            } else {
                $('body').append(audioElement);
            }

            currentTargetAudio = audioElement;
            currentTargetAudio.__sessionId = currentSession;
            activeAudioElements.set(currentTargetAudio, currentSession);
            try { currentTargetAudio.load(); } catch (_) { /* no-op */ }

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

            if (!shouldAutoplay) {
                return Promise.resolve(currentTargetAudio);
            }

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
            waitForAudioPlayable: waitForAudioPlayable,
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
