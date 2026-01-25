(function (root) {
    'use strict';
    const Effects = {
        startConfetti(opts) {
            const settings = Object.assign({ particleCount: 6, angle: 60, spread: 55, origin: null, duration: 2000 }, opts || {});
            try {
                let canvas = document.getElementById('confetti-canvas');
                const confettiZ = 1000002;
                if (!canvas) {
                    canvas = document.createElement('canvas');
                    canvas.id = 'confetti-canvas';
                    Object.assign(canvas.style, { position: 'fixed', top: 0, left: 0, width: '100%', height: '100%', pointerEvents: 'none', zIndex: confettiZ });
                    document.body.appendChild(canvas);
                } else {
                    canvas.style.zIndex = confettiZ;
                }
                if (typeof root.confetti !== 'function') return;

                const my = root.confetti.create(canvas, { resize: true, useWorker: true });
                const end = Date.now() + settings.duration;

                (function frame() {
                    if (settings.origin && typeof settings.origin.x === 'number' && typeof settings.origin.y === 'number') {
                        my({ particleCount: settings.particleCount, angle: settings.angle, spread: settings.spread, origin: settings.origin });
                    } else {
                        my({ particleCount: Math.floor(settings.particleCount / 2), angle: settings.angle, spread: settings.spread, origin: { x: 0.0, y: 0.5 } });
                        my({ particleCount: Math.ceil(settings.particleCount / 2), angle: 120, spread: settings.spread, origin: { x: 1.0, y: 0.5 } });
                    }
                    if (Date.now() < end) requestAnimationFrame(frame);
                })();
            } catch (e) {
                // no-op
            }
        }
    };
    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Effects = Effects;
})(window);
