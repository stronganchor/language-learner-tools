(function () {
    'use strict';

    var audio = new Audio();
    audio.preload = 'none';
    var currentButton = null;

    function stopAudio() {
        if (currentButton) {
            currentButton.classList.remove('is-playing');
        }
        currentButton = null;
        audio.pause();
        audio.currentTime = 0;
    }

    audio.addEventListener('ended', stopAudio);
    audio.addEventListener('pause', function () {
        if (currentButton) {
            currentButton.classList.remove('is-playing');
        }
    });

    document.addEventListener('click', function (event) {
        var btn = event.target.closest('.ll-study-recording-btn');
        if (!btn) {
            return;
        }
        event.preventDefault();
        if (btn.disabled) {
            return;
        }
        var url = btn.getAttribute('data-audio-url') || '';
        if (!url) {
            return;
        }
        if (currentButton && currentButton !== btn) {
            currentButton.classList.remove('is-playing');
        }
        if (currentButton === btn && !audio.paused) {
            stopAudio();
            return;
        }
        currentButton = btn;
        audio.src = url;
        audio.play().then(function () {
            btn.classList.add('is-playing');
        }).catch(function () {
            btn.classList.remove('is-playing');
        });
    });
})();
