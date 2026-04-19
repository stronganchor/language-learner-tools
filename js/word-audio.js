(function () {
    'use strict';

    var data = window.ll_word_audio_data || {};
    var controls = data.controls || {};
    var playLabel = controls.playLabel || 'Play audio';
    var pauseLabel = controls.pauseLabel || 'Pause audio';
    var playIconUrl = controls.playIconUrl || '';
    var pauseIconUrl = controls.pauseIconUrl || playIconUrl;

    function setButtonState(button, isPlaying) {
        var audioId = button.getAttribute('aria-controls');
        var audio = audioId ? document.getElementById(audioId) : null;
        var icon = button.querySelector('.ll-word-audio__icon-image');
        var label = button.querySelector('.ll-word-audio__label');

        if (!audio) {
            return;
        }

        if (isPlaying) {
            button.setAttribute('aria-label', pauseLabel);
            if (label) {
                label.textContent = pauseLabel;
            }
            if (icon && pauseIconUrl) {
                icon.src = pauseIconUrl;
            }
            return;
        }

        button.setAttribute('aria-label', playLabel);
        if (label) {
            label.textContent = playLabel;
        }
        if (icon && playIconUrl) {
            icon.src = playIconUrl;
        }
    }

    function stopAudio(audio) {
        audio.pause();
        audio.currentTime = 0;
    }

    function toggleAudio(button) {
        var audioId = button.getAttribute('aria-controls');
        var audio = audioId ? document.getElementById(audioId) : null;

        if (!audio) {
            return;
        }

        if (audio.paused) {
            audio.play();
            return;
        }

        stopAudio(audio);
    }

    function bindWordAudio(button) {
        var audioId = button.getAttribute('aria-controls');
        var audio = audioId ? document.getElementById(audioId) : null;

        if (!audio) {
            return;
        }

        button.addEventListener('click', function () {
            toggleAudio(button);
        });

        audio.addEventListener('play', function () {
            setButtonState(button, true);
        });

        audio.addEventListener('pause', function () {
            setButtonState(button, false);
        });

        audio.addEventListener('ended', function () {
            stopAudio(audio);
            setButtonState(button, false);
        });

        setButtonState(button, !audio.paused);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var buttons = document.querySelectorAll('.ll-word-audio__button[data-audio-id]');

        buttons.forEach(function (button) {
            bindWordAudio(button);
        });
    });
}());
