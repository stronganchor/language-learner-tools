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

    function getScrollBehavior() {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return 'auto';
        }

        return 'smooth';
    }

    function scrollCueIntoTranscript(transcript, activeButton) {
        if (!transcript || !activeButton || transcript.scrollHeight <= transcript.clientHeight) {
            return;
        }

        var transcriptRect = transcript.getBoundingClientRect();
        var buttonRect = activeButton.getBoundingClientRect();
        var topComfort = transcriptRect.top + Math.max(20, transcript.clientHeight * 0.16);
        var bottomComfort = transcriptRect.bottom - Math.max(34, transcript.clientHeight * 0.3);

        if (buttonRect.top >= topComfort && buttonRect.bottom <= bottomComfort) {
            return;
        }

        var buttonTop = transcript.scrollTop + (buttonRect.top - transcriptRect.top);
        var buttonCenter = buttonTop + (buttonRect.height / 2);
        var preferredCenter = transcript.clientHeight * 0.38;
        var maxScrollTop = Math.max(0, transcript.scrollHeight - transcript.clientHeight);
        var targetScrollTop = Math.max(0, Math.min(maxScrollTop, buttonCenter - preferredCenter));

        if (typeof transcript.scrollTo === 'function') {
            transcript.scrollTo({
                top: targetScrollTop,
                behavior: getScrollBehavior()
            });
            return;
        }

        transcript.scrollTop = targetScrollTop;
    }

    function setActiveCue(root, cueId) {
        var activeId = String(cueId || '');
        var previousActiveId = String(root.__llContentLessonActiveCueId || '');
        var buttons = root.querySelectorAll('[data-ll-content-lesson-cue]');
        var transcript = root.querySelector('[data-ll-content-lesson-transcript]');
        var activeButton = null;

        Array.prototype.forEach.call(buttons, function (button) {
            var isActive = activeId !== '' && String(button.getAttribute('data-cue-id') || '') === activeId;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            if (isActive) {
                activeButton = button;
            }
        });

        root.__llContentLessonActiveCueId = activeId;

        if (activeButton && activeId !== '' && activeId !== previousActiveId) {
            scrollCueIntoTranscript(transcript, activeButton);
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
