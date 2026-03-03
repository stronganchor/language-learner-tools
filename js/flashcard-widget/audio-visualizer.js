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
    let noiseGateOpen = false;
    let noiseGateHoldFrames = 0;
    const NOISE_GATE_OPEN_THRESHOLD = 0.2;
    const NOISE_GATE_CLOSE_THRESHOLD = 0.12;
    const NOISE_GATE_HOLD_FRAMES = 5;

    function getContainer() {
        // Use only the dedicated listening-mode visualizer container.
        // The global #ll-tools-loading-animation is reserved for generic loading.
        if (root.document) {
            const listeningEl = root.document.getElementById('ll-tools-listening-visualizer');
            if (listeningEl) {
                return listeningEl;
            }
        }
        return null;
    }

    function ensureBars() {
        const el = getContainer();
        if (!el) return null;

        // If container changed (new placeholder each word), rebuild bars
        if (bars.length && bars[0] && bars[0].parentNode !== el) {
            bars = [];
            barLevels = [];
        }

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

    function warmupAudioContext() {
        const ctx = ensureAudioContext();
        if (!ctx) return Promise.resolve(false);
        if (ctx.state === 'running') return Promise.resolve(true);
        if (typeof ctx.resume !== 'function') return Promise.resolve(false);
        return Promise.resolve(ctx.resume()).then(function () {
            return ctx.state === 'running';
        }).catch(function () {
            return false;
        });
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
        noiseGateOpen = false;
        noiseGateHoldFrames = 0;
    }

    function revealVisualizer() {
        const el = getContainer();
        if (!el) return;
        try {
            el.style.display = 'flex';
            el.style.visibility = 'visible';
        } catch (_) {}
        for (let i = 0; i < bars.length; i++) {
            try {
                bars[i].style.removeProperty('display');
                bars[i].style.removeProperty('opacity');
            } catch (_) {}
        }
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

    function renderTimelineLevels(audio) {
        if (!bars.length || !audio) return;

        const duration = (typeof audio.duration === 'number' && isFinite(audio.duration) && audio.duration > 0)
            ? audio.duration
            : 0;
        const currentTime = Math.max(0, (typeof audio.currentTime === 'number' && isFinite(audio.currentTime)) ? audio.currentTime : 0);
        const progress = duration > 0 ? Math.max(0, Math.min(1, currentTime / duration)) : 0;
        const barSpan = Math.max(1, bars.length - 1);

        for (let i = 0; i < bars.length; i++) {
            const x = i / barSpan;
            const dist = Math.abs(x - progress);
            const sweep = Math.max(0, 1 - (dist * 2.2));
            const sweepSoft = Math.pow(sweep, 1.25);

            // Blend a progress sweep with time-varying ripples so bars remain
            // motion-coupled to playback when analyser data is unavailable.
            const rippleA = Math.abs(Math.sin((currentTime * 7.4) + (i * 0.62)));
            const rippleB = Math.abs(Math.sin((currentTime * 4.1) + (i * 0.37)));
            const ripple = (rippleA * 0.62) + (rippleB * 0.38);

            // Keep fallback motion subtle; reserve near-max heights for real analyser peaks.
            const target = Math.max(0.04, Math.min(0.64, (sweepSoft * 0.38) + (ripple * 0.18) + 0.04));
            const previous = barLevels[i] || 0;
            const level = previous + (target - previous) * 0.42;
            barLevels[i] = level;
            bars[i].style.setProperty('--level', level.toFixed(3));
        }
    }

    function loop() {
        if (!analyser || !bars.length || !analyserData || !timeDomainData) {
            rafId = null;
            return;
        }

        if (!isContextRunning()) {
            if (currentAudio && !currentAudio.paused && !currentAudio.ended) {
                activateJS();
                renderTimelineLevels(currentAudio);
            } else {
                activateFallback();
            }
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
        const rms = Math.min(1, Math.sqrt(sumSquares / timeDomainData.length) / 66);
        let spectrumSum = 0;
        for (let i = 0; i < analyserData.length; i++) {
            spectrumSum += analyserData[i];
        }
        const spectrumAvg = spectrumSum / analyserData.length;
        const rmsSignal = Math.max(0, Math.min(1, (rms - 0.035) / 0.1));
        const spectrumSignal = Math.max(0, Math.min(1, (spectrumAvg - 28) / 80));
        // De-emphasize spectrum-only activity so static/hiss does not open the gate too easily.
        const frameSignal = Math.max(rmsSignal, spectrumSignal * 0.55);

        if (frameSignal >= NOISE_GATE_OPEN_THRESHOLD) {
            noiseGateOpen = true;
            noiseGateHoldFrames = NOISE_GATE_HOLD_FRAMES;
        } else if (noiseGateOpen) {
            if (frameSignal >= NOISE_GATE_CLOSE_THRESHOLD) {
                noiseGateHoldFrames = NOISE_GATE_HOLD_FRAMES;
            } else if (noiseGateHoldFrames > 0) {
                noiseGateHoldFrames -= 1;
            } else {
                noiseGateOpen = false;
            }
        }

        const loudFrame = noiseGateOpen && rms > 0.55;

        for (let i = 0; i < bars.length; i++) {
            const previous = barLevels[i] || 0;

            if (!noiseGateOpen) {
                const level = previous * 0.7;
                barLevels[i] = level;
                bars[i].style.setProperty('--level', level.toFixed(3));
                continue;
            }

            let sum = 0;
            for (let j = 0; j < slice; j++) {
                sum += analyserData[(i * slice) + j] || 0;
            }

            const avg = sum / slice;
            const normalized = Math.max(0, (avg - 40) / 220);
            const combined = Math.min(1, (normalized * 0.64) + (rms * 0.72));
            const boosted = Math.min(1, combined * 1.22);
            const shaped = Math.pow(boosted, 1.22);
            const gateBoost = 0.18 + (frameSignal * 0.82);
            const eased = Math.min(loudFrame ? 1 : 0.84, shaped * gateBoost);

            const level = previous + (eased - previous) * 0.38;
            barLevels[i] = level;

            if (level > 0.03) {
                hasEnergy = true;
            }

            bars[i].style.setProperty('--level', level.toFixed(3));
        }

        if (hasEnergy) {
            zeroEnergyFrames = 0;
        } else {
            zeroEnergyFrames++;
            if (
                currentAudio && !currentAudio.paused && !currentAudio.ended &&
                noiseGateOpen && frameSignal >= NOISE_GATE_CLOSE_THRESHOLD &&
                zeroEnergyFrames > 10
            ) {
                renderTimelineLevels(currentAudio);
            }
        }
        // While audio is actively playing, keep bars JS-driven (not fixed-rate fallback).
        activateJS();

        rafId = root.requestAnimationFrame(loop);
    }

    function startLoop() {
        cancelLoop();
        zeroEnergyFrames = 0;
        loop();
    }

    function detachAudioEvents() {
        if (!currentAudio) return;
        currentAudio.removeEventListener('play', handlePlaying);
        currentAudio.removeEventListener('pause', handlePause);
        currentAudio.removeEventListener('ended', handleEnded);
        currentAudio.removeEventListener('playing', handlePlaying);
        currentAudio.removeEventListener('timeupdate', handleTimeUpdate);
        currentAudio = null;
    }

    function handlePlaying() {
        revealVisualizer();
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

    function handleTimeUpdate() {
        if (!currentAudio) return;
        if (currentAudio.currentTime <= 0 && !currentAudio.ended) return;

        // Backup trigger: if play/playing was missed in some browsers/races,
        // start JS visual updates once timeline movement is observed.
        const el = getContainer();
        if (el && !el.classList.contains(CLASS_JS) && !currentAudio.paused) {
            handlePlaying();
            return;
        }

        if (!isContextRunning() && !currentAudio.paused && !currentAudio.ended) {
            activateJS();
            renderTimelineLevels(currentAudio);
        }
    }

    function prepareForListening() {
        const el = ensureBars();
        if (!el) return;
        activateFallback();
        revealVisualizer();
        el.classList.remove(CLASS_ACTIVE);
        resetBars();
    }

    function followAudio(audio) {
        const el = ensureBars();
        if (!el || !audio) return;

        const ctx = ensureAudioContext();
        if (!ctx) {
            activateFallback();
            revealVisualizer();
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

        let source = audio.__llVisualizerSource || audio.__llMiniVisualizerSource;
        if (!source) {
            try {
                source = ctx.createMediaElementSource(audio);
                // Keep a single source node alias so mini + listening visualizers
                // can attach in any order without createMediaElementSource conflicts.
                audio.__llVisualizerSource = source;
                audio.__llMiniVisualizerSource = source;
            } catch (err) {
                console.warn('AudioVisualizer: media source failed', err);
                activateFallback();
                return;
            }
        } else {
            // If one visualizer created the source first, mirror aliases so
            // the other visualizer can always discover and reuse it.
            audio.__llVisualizerSource = source;
            audio.__llMiniVisualizerSource = source;
        }

        try {
            source.disconnect(analyser);
        } catch (_) {
            // Ignore disconnect errors from nodes without an existing analyser connection
        }

        try {
            source.connect(analyser);
        } catch (err) {
            console.warn('AudioVisualizer: connect failed', err);
            activateFallback();
            return;
        }

        if (isContextRunning() && !currentAudio.paused) {
            activateJS();
        } else {
            activateFallback();
        }
        revealVisualizer();

        currentAudio.addEventListener('play', handlePlaying, { passive: true });
        currentAudio.addEventListener('pause', handlePause, { passive: true });
        currentAudio.addEventListener('ended', handleEnded, { passive: true });
        currentAudio.addEventListener('playing', handlePlaying, { passive: true });
        currentAudio.addEventListener('timeupdate', handleTimeUpdate, { passive: true });

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

    function createMiniVisualizer(options) {
        const cfg = Object.assign({
            barSelector: '.bar',
            barClass: 'bar',
            barCount: 0,
            activeClass: 'll-audio-mini-visualizer--js',
            noiseFloor: 25,
            freqRange: 180,
            rmsScale: 52,
            freqWeight: 0.7,
            rmsWeight: 1.05,
            gain: 2,
            levelPow: 1.05,
            smoothing: 0.5
        }, options || {});

        let miniAudio = null;
        let miniContainer = null;
        let miniBars = [];
        let miniLevels = [];
        let miniRafId = null;
        let miniAnalyser = null;
        let miniAnalyserData = null;
        let miniTimeData = null;
        let miniSource = null;
        let miniListeners = [];

        function ensureMiniBars(container) {
            if (!container) { return []; }
            let bars = Array.from(container.querySelectorAll(cfg.barSelector));
            if (!bars.length && cfg.barCount > 0 && root.document) {
                const frag = root.document.createDocumentFragment();
                for (let i = 0; i < cfg.barCount; i++) {
                    const span = root.document.createElement('span');
                    span.className = cfg.barClass;
                    frag.appendChild(span);
                    bars.push(span);
                }
                container.appendChild(frag);
            }
            return bars;
        }

        function ensureMiniAnalyser() {
            const ctx = ensureAudioContext();
            if (!ctx) return null;
            if (!miniAnalyser) {
                miniAnalyser = ctx.createAnalyser();
                miniAnalyser.fftSize = 256;
                miniAnalyser.smoothingTimeConstant = 0.65;
                miniAnalyserData = new Uint8Array(miniAnalyser.frequencyBinCount);
                miniTimeData = new Uint8Array(miniAnalyser.fftSize);
            }
            if (!miniAnalyser.__llConnected) {
                try {
                    miniAnalyser.connect(ctx.destination);
                    miniAnalyser.__llConnected = true;
                } catch (_) {}
            }
            return miniAnalyser;
        }

        function resetMiniBars() {
            if (!miniBars.length) return;
            miniBars.forEach(function (bar) {
                bar.style.setProperty('--level', '0');
            });
            miniLevels = miniBars.map(() => 0);
        }

        function cancelMiniLoop() {
            if (miniRafId) {
                root.cancelAnimationFrame(miniRafId);
                miniRafId = null;
            }
        }

        function pauseMini() {
            cancelMiniLoop();
            resetMiniBars();
        }

        function stopMini() {
            pauseMini();
            if (miniAudio && miniListeners.length) {
                miniListeners.forEach(function (item) {
                    try { miniAudio.removeEventListener(item.type, item.handler); } catch (_) {}
                });
            }
            miniListeners = [];
            if (miniSource && miniAnalyser) {
                try { miniSource.disconnect(miniAnalyser); } catch (_) {}
            }
            if (miniContainer && cfg.activeClass) {
                try { miniContainer.classList.remove(cfg.activeClass); } catch (_) {}
            }
            miniAudio = null;
            miniContainer = null;
            miniBars = [];
            miniLevels = [];
            miniSource = null;
        }

        function updateMini() {
            if (!miniAnalyser || !miniBars.length || !miniAnalyserData || !miniTimeData) {
                miniRafId = null;
                return;
            }
            if (!miniAudio || miniAudio.paused) {
                pauseMini();
                return;
            }
            const ctx = ensureAudioContext();
            if (!ctx || ctx.state !== 'running') {
                miniRafId = root.requestAnimationFrame(updateMini);
                return;
            }

            miniAnalyser.getByteFrequencyData(miniAnalyserData);
            miniAnalyser.getByteTimeDomainData(miniTimeData);

            const slice = Math.max(1, Math.floor(miniAnalyserData.length / miniBars.length));
            let sumSquares = 0;
            for (let i = 0; i < miniTimeData.length; i++) {
                const deviation = miniTimeData[i] - 128;
                sumSquares += deviation * deviation;
            }
            const rms = Math.min(1, Math.sqrt(sumSquares / miniTimeData.length) / cfg.rmsScale);

            for (let i = 0; i < miniBars.length; i++) {
                let sum = 0;
                for (let j = 0; j < slice; j++) {
                    sum += miniAnalyserData[(i * slice) + j] || 0;
                }
                const avg = sum / slice;
                const normalized = Math.max(0, (avg - cfg.noiseFloor) / cfg.freqRange);
                const combined = Math.min(1, (normalized * cfg.freqWeight) + (rms * cfg.rmsWeight));
                const boosted = Math.min(1, combined * cfg.gain);
                const eased = Math.pow(boosted, cfg.levelPow);

                const previous = miniLevels[i] || 0;
                const level = previous + (eased - previous) * cfg.smoothing;
                miniLevels[i] = level;
                miniBars[i].style.setProperty('--level', level.toFixed(3));
            }

            miniRafId = root.requestAnimationFrame(updateMini);
        }

        function startMini() {
            cancelMiniLoop();
            updateMini();
        }

        function attachMini(audio, container) {
            if (!audio || !container) {
                stopMini();
                return;
            }

            stopMini();
            miniAudio = audio;
            miniContainer = container;
            miniBars = ensureMiniBars(container);
            miniLevels = miniBars.map(() => 0);
            if (!miniBars.length) { return; }

            const ctx = ensureAudioContext();
            const analyser = ensureMiniAnalyser();
            if (!ctx || !analyser) { return; }

            let source = audio.__llMiniVisualizerSource || audio.__llVisualizerSource;
            if (!source) {
                try {
                    source = ctx.createMediaElementSource(audio);
                    audio.__llMiniVisualizerSource = source;
                    audio.__llVisualizerSource = source;
                } catch (_) {
                    return;
                }
            } else {
                audio.__llMiniVisualizerSource = source;
                audio.__llVisualizerSource = source;
            }

            miniSource = source;
            try { source.disconnect(analyser); } catch (_) {}
            try {
                source.connect(analyser);
            } catch (_) {
                return;
            }

            const onPlay = function () {
                if (ctx.state === 'suspended') {
                    ctx.resume().then(function () {
                        if (cfg.activeClass) {
                            try { container.classList.add(cfg.activeClass); } catch (_) {}
                        }
                        startMini();
                    }).catch(function () {
                        if (cfg.activeClass) {
                            try { container.classList.remove(cfg.activeClass); } catch (_) {}
                        }
                    });
                } else {
                    if (cfg.activeClass) {
                        try { container.classList.add(cfg.activeClass); } catch (_) {}
                    }
                    startMini();
                }
            };
            const onStop = function () { pauseMini(); };

            miniListeners = [
                { type: 'play', handler: onPlay },
                { type: 'playing', handler: onPlay },
                { type: 'pause', handler: onStop },
                { type: 'ended', handler: onStop },
                { type: 'emptied', handler: onStop },
                { type: 'error', handler: onStop }
            ];
            miniListeners.forEach(function (item) {
                try { audio.addEventListener(item.type, item.handler, { passive: true }); }
                catch (_) { audio.addEventListener(item.type, item.handler); }
            });

            if (!audio.paused && !audio.ended) {
                onPlay();
            } else {
                resetMiniBars();
            }
        }

        return { attach: attachMini, stop: stopMini };
    }

    namespace.AudioVisualizer = {
        prepareForListening: prepareForListening,
        followAudio: followAudio,
        stop: stopVisualizer,
        reset: resetVisualizer,
        warmup: warmupAudioContext,
        createMiniVisualizer: createMiniVisualizer
    };
})(window);
