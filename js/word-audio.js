var activeAudioPlayers = [];
var maxAudioPlayers = 500;

function createAudioPlayer(wordElement) {
    var audioUrl = wordElement.getAttribute('data-audio-url');
    var audioPlayer = new Audio(audioUrl);
    
    audioPlayer.addEventListener('play', function() {
        pauseAllAudioPlayers();
    });
    
    audioPlayer.addEventListener('ended', function() {
        destroyAudioPlayer(audioPlayer);
    });
    
    activeAudioPlayers.push(audioPlayer);
    
    if (activeAudioPlayers.length > maxAudioPlayers) {
        var oldestAudioPlayer = activeAudioPlayers.shift();
        destroyAudioPlayer(oldestAudioPlayer);
    }
}

function destroyAudioPlayer(audioPlayer) {
    audioPlayer.pause();
    audioPlayer.src = '';
    audioPlayer.load();
    var index = activeAudioPlayers.indexOf(audioPlayer);
    if (index > -1) {
        activeAudioPlayers.splice(index, 1);
    }
}
// Use the plugin's directory URL to construct the paths for the SVG files
var pluginDirUrl = ll_word_audio_data.plugin_dir_url;
var play_icon = '<img src="' + pluginDirUrl + 'media/play-symbol.svg" width="10" height="10" alt="Play" data-no-lazy="1">';
var stop_icon = '<img src="' + pluginDirUrl + 'media/stop-symbol.svg" width="9" height="9" alt="Stop" data-no-lazy="1">';

// Rest of the JavaScript code...
function ll_toggleAudio(audioId) {
    var audio = document.getElementById(audioId);
    var icon = document.getElementById(audioId + '_icon');
    if (!audio.paused) {
        audio.pause();
        audio.currentTime = 0; // Stop the audio
        icon.innerHTML = play_icon;
    } else {
        audio.play();
    }
}

function ll_audioPlaying(audioId) {
    var icon = document.getElementById(audioId + '_icon');
    icon.innerHTML = stop_icon;
}

function ll_audioEnded(audioId) {
    var icon = document.getElementById(audioId + '_icon');
    icon.innerHTML = play_icon;
}

function pauseAllAudioPlayers() {
    activeAudioPlayers.forEach(function(audioPlayer) {
        audioPlayer.pause();
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var wordElements = document.querySelectorAll('.ll-word-audio');
    
    // Load the first 500 audio players
    for (var i = 0; i < maxAudioPlayers && i < wordElements.length; i++) {
        createAudioPlayer(wordElements[i]);
    }
    
    wordElements.forEach(function(wordElement) {
        wordElement.addEventListener('click', function() {
            var audioPlayer = activeAudioPlayers.find(function(player) {
                return player.src === wordElement.getAttribute('data-audio-url');
            });
            
            if (audioPlayer) {
                if (audioPlayer.paused) {
                    pauseAllAudioPlayers();
                    audioPlayer.play();
                } else {
                    audioPlayer.pause();
                }
            } else {
                createAudioPlayer(wordElement);
            }
        });
    });
});