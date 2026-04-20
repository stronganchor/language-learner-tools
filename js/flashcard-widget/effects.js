(function (root) {
    'use strict';

    let confettiCanvas = null;
    let confettiInstance = null;
    let confettiGeneration = 0;

    function ensureCanvas() {
        if (!root.document || !root.document.body) {
            return null;
        }

        if (confettiCanvas && !confettiCanvas.isConnected) {
            confettiCanvas = null;
            confettiInstance = null;
        }

        let canvas = confettiCanvas || root.document.getElementById('confetti-canvas');
        const confettiZ = 1000002;

        if (!canvas) {
            canvas = root.document.createElement('canvas');
            canvas.id = 'confetti-canvas';
            Object.assign(canvas.style, {
                position: 'fixed',
                top: 0,
                left: 0,
                width: '100%',
                height: '100%',
                pointerEvents: 'none',
                zIndex: confettiZ
            });
            root.document.body.appendChild(canvas);
        } else {
            canvas.style.zIndex = confettiZ;
        }

        confettiCanvas = canvas;
        return canvas;
    }

    function ensureInstance() {
        if (typeof root.confetti !== 'function') {
            return null;
        }

        if (confettiInstance) {
            return confettiInstance;
        }

        const canvas = ensureCanvas();
        if (!canvas) {
            return null;
        }

        if (typeof root.confetti.create !== 'function') {
            confettiInstance = root.confetti;
            return confettiInstance;
        }

        // The worker-backed instance has produced noisy blob-worker errors on
        // quick popup open/close cycles. These bursts are small, so keep the
        // rendering on the main thread and reset the same instance explicitly.
        confettiInstance = root.confetti.create(canvas, { resize: true, useWorker: false });
        return confettiInstance;
    }

    function scheduleFrame(callback) {
        if (typeof root.requestAnimationFrame === 'function') {
            root.requestAnimationFrame(callback);
            return;
        }

        root.setTimeout(callback, 16);
    }

    function removeCanvas() {
        const canvas = confettiCanvas || (root.document && root.document.getElementById('confetti-canvas'));
        if (canvas && canvas.parentNode) {
            canvas.parentNode.removeChild(canvas);
        }
        confettiCanvas = null;
    }

    const Effects = {
        startConfetti(opts) {
            const settings = Object.assign({ particleCount: 6, angle: 60, spread: 55, origin: null, duration: 2000 }, opts || {});
            try {
                const my = ensureInstance();
                if (!my) {
                    return;
                }

                const end = Date.now() + settings.duration;
                const generation = confettiGeneration;

                (function frame() {
                    if (generation !== confettiGeneration) {
                        return;
                    }

                    if (settings.origin && typeof settings.origin.x === 'number' && typeof settings.origin.y === 'number') {
                        my({ particleCount: settings.particleCount, angle: settings.angle, spread: settings.spread, origin: settings.origin });
                    } else {
                        my({ particleCount: Math.floor(settings.particleCount / 2), angle: settings.angle, spread: settings.spread, origin: { x: 0.0, y: 0.5 } });
                        my({ particleCount: Math.ceil(settings.particleCount / 2), angle: 120, spread: settings.spread, origin: { x: 1.0, y: 0.5 } });
                    }
                    if (Date.now() < end) {
                        scheduleFrame(frame);
                    }
                })();
            } catch (e) {
                // no-op
            }
        },
        resetConfetti() {
            confettiGeneration += 1;
            try {
                if (confettiInstance && typeof confettiInstance.reset === 'function') {
                    confettiInstance.reset();
                }
            } catch (_) {
                // no-op
            }
            confettiInstance = null;
            removeCanvas();
        }
    };
    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Effects = Effects;
})(window);
