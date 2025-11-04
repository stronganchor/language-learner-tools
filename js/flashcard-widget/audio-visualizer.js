(function (root) {
    'use strict';

    const namespace = (root.LLFlashcards = root.LLFlashcards || {});

    const CLASS_BASE = 'll-tools-loading-animation--visualizer';
    const CLASS_ACTIVE = 'll-tools-loading-animation--active';
    const CLASS_JS = 'll-tools-loading-animation--js';
    const CLASS_FALLBACK = 'll-tools-loading-animation--fallback';
    const BAR_COUNT = 12;

    let container = null;
    let bars = [];
    let rafId = null;
    let audioContext = null;
    let analyser = null;
    let analyserData = null;
    let timeDomainData = null;
    let analyserConnected = false;
    let currentAudio = null;
    let barLevels = [];
    let zeroEnergyFrames = 0;
    const ZERO_ENERGY_FALLBACK_FRAMES = 90;

    function getContainer() {
        if (!container) {
            container = root.document
                ? root.document.getElementById('ll-tools-loading-animation')
                : null;
        }
        return container;
    }

    function ensureBars() {
        const el = getContainer();
        if (!el) return null;

        if (!bars.length) {
            const existing = el.querySelectorAll('.ll-tools-visualizer-bar');
            if (existing.length) {
                bars = Array.from(existing);
                barLevels = bars.map(() => 0);
            } else {
                const frag = root.document.createDocumentFragment();
                for (let i = 0; i < BAR_COUNT; i++) {
                    const span = root.document.createElement('span');
                    span.className = 'll-tools-visualizer-bar';
                    span.style.setProperty('--offset', (i / BAR_COUNT).toFixed(3));
                    span.style.setProperty('--level', '0');
                    frag.appendChild(span);
                    bars.push(span);
                    barLevels.push(0);
                }
                el.appendChild(frag);
            }
        } else if (barLevels.length !== bars.length) {
            barLevels = bars.map(() => 0);
        }
        return el;
    }

    function ensureAudioContext() {
        if (audioContext) return audioContext;
        const Ctor = root.AudioContext || root.webkitAudioContext;
        if (!Ctor) return null;
        try {
            audioContext = new Ctor();
        } catch (err) {
            console.warn('AudioVisualizer: unable to create AudioContext', err);
            audioContext = null;
        }
        return audioContext;
    }

    function ensureAnalyser() {
        if (!audioContext) return null;
        if (!analyser) {
            analyser = audioContext.createAnalyser();
            analyser.fftSize = 256;
            analyser.smoothingTimeConstant = 0.65;
            analyserData = new Uint8Array(analyser.frequencyBinCount);
            timeDomainData = new Uint8Array(analyser.fftSize);
        }
        if (!analyserConnected) {
            try {
                analyser.connect(audioContext.destination);
                analyserConnected = true;
            } catch (err) {
                console.warn('AudioVisualizer: analyser connect failed', err);
            }
        }
        return analyser;
    }

    function cancelLoop() {
        if (rafId) {
            root.cancelAnimationFrame(rafId);
            rafId = null;
        }
    }

    function resetBars() {
        if (!bars.length) return;
        for (let i = 0; i < bars.length; i++) {
            bars[i].style.setProperty('--level', '0');
            barLevels[i] = 0;
        }
        zeroEnergyFrames = 0;
    }

    function activateFallback() {
        const el = getContainer();
        if (!el) return;
        el.classList.add(CLASS_BASE);
        el.classList.add(CLASS_FALLBACK);
        el.classList.remove(CLASS_JS);
    }

    function activateJS() {
        const el = getContainer();
        if (!el) return;
        el.classList.add(CLASS_BASE);
        el.classList.add(CLASS_JS);
        el.classList.remove(CLASS_FALLBACK);
    }

    function isContextRunning() {
        return !!(audioContext && audioContext.state === 'running');
    }

    function loop() {
        if (!analyser || !bars.length || !analyserData || !timeDomainData) {
            rafId = null;
            return;
        }

        if (!isContextRunning()) {
            activateFallback();
            rafId = root.requestAnimationFrame(loop);
            return;
        }

        analyser.getByteFrequencyData(analyserData);
        analyser.getByteTimeDomainData(timeDomainData);

        const slice = Math.max(1, Math.floor(analyserData.length / bars.length));
        let hasEnergy = false;

        // RMS gives us overall loudness to mix into the bars
        let sumSquares = 0;
        for (let i = 0; i < timeDomainData.length; i++) {
            const deviation = timeDomainData[i] - 128;
            sumSquares += deviation * deviation;
        }
        const rms = Math.min(1, Math.sqrt(sumSquares / timeDomainData.length) / 64); // boost factor

        for (let i = 0; i < bars.length; i++) {
            let sum = 0;
            for (let j = 0; j < slice; j++) {
                sum += analyserData[(i * slice) + j] || 0;
            }

            const avg = sum / slice;
            const normalized = Math.max(0, (avg - 40) / 215); // ignore very low noise floor
            const combined = Math.min(1, (normalized * 0.7) + (rms * 0.9));
            const eased = Math.pow(combined, 1.35);

            const previous = barLevels[i] || 0;
            const level = previous + (eased - previous) * 0.35;
            barLevels[i] = level;

            if (level > 0.05) {
                hasEnergy = true;
            }

            bars[i].style.setProperty('--level', level.toFixed(3));
        }

        if (hasEnergy) {
            zeroEnergyFrames = 0;
            activateJS();
        } else {
            zeroEnergyFrames++;
            if (zeroEnergyFrames > ZERO_ENERGY_FALLBACK_FRAMES) {
                activateFallback();
            }
        }

        rafId = root.requestAnimationFrame(loop);
    }

    function startLoop() {
        cancelLoop();
        zeroEnergyFrames = 0;
        loop();
    }

    function detachAudioEvents() {
        if (!currentAudio) return;
        currentAudio.removeEventListener('pause', handlePause);
        currentAudio.removeEventListener('ended', handleEnded);
        currentAudio.removeEventListener('playing', handlePlaying);
        currentAudio.removeEventListener('emptied', handleEnded);
        currentAudio = null;
    }

    function handlePlaying() {
        const el = getContainer();
        if (el) el.classList.add(CLASS_ACTIVE);

        if (!audioContext) {
            activateFallback();
            return;
        }

        const startIfRunning = function () {
            if (isContextRunning()) {
                activateJS();
                startLoop();
            } else {
                activateFallback();
            }
        };

        if (audioContext.state === 'suspended') {
            audioContext.resume().then(startIfRunning).catch(function () {
                activateFallback();
            });
        } else {
            startIfRunning();
        }
    }

    function handlePause() {
        if (!currentAudio) return;
        if (currentAudio.ended || (currentAudio.duration && Math.abs(currentAudio.currentTime - currentAudio.duration) < 0.05)) {
            handleEnded();
            return;
        }
        cancelLoop();
        activateFallback();
    }

    function handleEnded() {
        cancelLoop();
        resetBars();
        detachAudioEvents();
        const el = getContainer();
        if (el) el.classList.remove(CLASS_ACTIVE);
    }

    function prepareForListening() {
        const el = ensureBars();
        if (!el) return;
        activateFallback();
        try { el.style.display = 'flex'; } catch (_) {}
        el.classList.remove(CLASS_ACTIVE);
        resetBars();
    }

    function followAudio(audio) {
        const el = ensureBars();
        if (!el || !audio) return;

        const ctx = ensureAudioContext();
        if (!ctx) {
            activateFallback();
            try { el.style.display = 'flex'; } catch (_) {}
            el.classList.add(CLASS_ACTIVE);
            return;
        }

        ensureAnalyser();
        if (!analyser) {
            activateFallback();
            return;
        }

        detachAudioEvents();
        currentAudio = audio;

        let source = audio.__llVisualizerSource;
        if (!source) {
            try {
                source = ctx.createMediaElementSource(audio);
                audio.__llVisualizerSource = source;
            } catch (err) {
                console.warn('AudioVisualizer: media source failed', err);
                activateFallback();
                return;
            }
        }

        try {
            source.disconnect();
        } catch (_) {
            // Ignore disconnect errors from nodes without existing connections
        }

        try {
            source.connect(analyser);
        } catch (err) {
            console.warn('AudioVisualizer: connect failed', err);
            activateFallback();
            return;
        }

        if (isContextRunning()) {
            activateJS();
        } else {
            activateFallback();
        }
        try { el.style.display = 'flex'; } catch (_) {}

        currentAudio.addEventListener('pause', handlePause, { passive: true });
        currentAudio.addEventListener('ended', handleEnded, { passive: true });
        currentAudio.addEventListener('playing', handlePlaying, { passive: true });
        currentAudio.addEventListener('emptied', handleEnded, { passive: true });

        if (!currentAudio.paused) {
            handlePlaying();
        } else {
            resetBars();
        }
    }

    function stopVisualizer() {
        cancelLoop();
        resetBars();
        detachAudioEvents();
        const el = getContainer();
        if (!el) return;
        el.classList.remove(CLASS_ACTIVE);
        activateFallback();
    }

    function resetVisualizer() {
        stopVisualizer();
        const el = getContainer();
        if (!el) return;
        el.classList.remove(CLASS_JS);
        el.classList.remove(CLASS_BASE);
        el.classList.remove(CLASS_FALLBACK);
    }

    namespace.AudioVisualizer = {
        prepareForListening: prepareForListening,
        followAudio: followAudio,
        stop: stopVisualizer,
        reset: resetVisualizer
    };
})(window);
