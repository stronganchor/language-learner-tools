(function () {
    'use strict';

    function parseCueRows(root) {
        var script = root.querySelector('[data-ll-content-lesson-cues]');
        if (!script) {
            return [];
        }

        try {
            var parsed = JSON.parse(script.textContent || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    function setActiveCue(root, cueId) {
        var activeId = String(cueId || '');
        var buttons = root.querySelectorAll('[data-ll-content-lesson-cue]');
        var activeButton = null;

        Array.prototype.forEach.call(buttons, function (button) {
            var isActive = activeId !== '' && String(button.getAttribute('data-cue-id') || '') === activeId;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            if (isActive) {
                activeButton = button;
            }
        });

        if (activeButton && typeof activeButton.scrollIntoView === 'function') {
            var rect = activeButton.getBoundingClientRect();
            var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
            if (viewportHeight > 0 && (rect.top < 110 || rect.bottom > (viewportHeight - 90))) {
                activeButton.scrollIntoView({
                    block: 'nearest',
                    inline: 'nearest',
                    behavior: 'smooth'
                });
            }
        }
    }

    function findCurrentCue(cues, currentMs) {
        for (var index = 0; index < cues.length; index++) {
            var cue = cues[index];
            var startMs = parseInt(cue && cue.start_ms, 10) || 0;
            var endMs = parseInt(cue && cue.end_ms, 10) || 0;
            if (endMs > startMs && currentMs >= startMs && currentMs < endMs) {
                return cue;
            }
        }

        return null;
    }

    function bindLessonPlayer(root) {
        if (!root || root.__llContentLessonBound) {
            return;
        }
        root.__llContentLessonBound = true;

        var media = root.querySelector('[data-ll-content-lesson-media]');
        var cues = parseCueRows(root);
        if (!media || !cues.length) {
            return;
        }

        var updateActiveState = function () {
            var currentMs = Math.max(0, Math.round((Number(media.currentTime) || 0) * 1000));
            var cue = findCurrentCue(cues, currentMs);
            setActiveCue(root, cue ? (cue.id || '') : '');
        };

        Array.prototype.forEach.call(root.querySelectorAll('[data-ll-content-lesson-cue]'), function (button) {
            button.addEventListener('click', function () {
                var startMs = parseInt(button.getAttribute('data-start-ms') || '0', 10) || 0;
                media.currentTime = Math.max(0, startMs) / 1000;
                if (typeof media.play === 'function') {
                    Promise.resolve(media.play()).catch(function () {});
                }
                updateActiveState();
            });
        });

        media.addEventListener('timeupdate', updateActiveState);
        media.addEventListener('seeked', updateActiveState);
        media.addEventListener('loadedmetadata', updateActiveState);
        media.addEventListener('ended', function () {
            setActiveCue(root, '');
        });

        updateActiveState();
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-ll-content-lesson-player]'), bindLessonPlayer);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
