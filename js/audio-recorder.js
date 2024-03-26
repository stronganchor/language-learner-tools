var mediaRecorder;
var audioChunks = [];
var isRecording = false;
// Add at the beginning of the file
var uidGlobal;

// Modify the sendAudioToServer function
function sendAudioToServer(audioBlob, uid) {
    var formData = new FormData();
    formData.append('action', 'process_audio_transcription'); // This corresponds to your WordPress AJAX action
    formData.append('audioFile', audioBlob, uid + '.mp3');
    formData.append('security', my_ajax_object.audio_nonce); // Include nonce in AJAX request

    fetch(my_ajax_object.ajax_url, { // my_ajax_object.ajax_url should be defined in PHP and localized to your script
        method: 'POST',
        credentials: 'same-origin', // Needed for WordPress to recognize the logged in user
        body: formData,
    })
    .then(response => response.json()) // Parsing the JSON response
    .then(data => {
        if (data.success) {
            document.getElementById('transcription_' + uid).textContent = data.data.transcription; // Update this line to set the transcription text
        } else {
            console.error('Error transcribing audio:', data.data);
            document.getElementById('transcription_' + uid).textContent = 'Error transcribing audio.';
        }
    })
    .catch(error => {
        console.error('Error transcribing audio:', error);
        document.getElementById('transcription_' + uid).textContent = 'Error transcribing audio.';
    });
}

function toggleRecording(button, uid) {
    if (!navigator.mediaDevices) {
        alert('Audio recording is not supported in your browser');
        return;
    }

    if (!isRecording) {
        navigator.mediaDevices.getUserMedia({ audio: true })
            .then(stream => {
                mediaRecorder = new MediaRecorder(stream);
                mediaRecorder.ondataavailable = e => {
                    audioChunks.push(e.data);
                };
                mediaRecorder.onstop = () => {
                    const audioBlob = new Blob(audioChunks, { type: 'audio/mp3' });
                    const audioUrl = URL.createObjectURL(audioBlob);
                    const audio = document.getElementById('audio_playback_' + uid);
                    audio.src = audioUrl;
                    audio.hidden = false;

                    sendAudioToServer(audioBlob, uid);
                };
                audioChunks = [];
                mediaRecorder.start();
                button.textContent = 'Stop Recording';
            })
            .catch(err => {
                console.error('An error occurred:', err);
                alert('An error occurred while trying to start the audio recording.');
            });
    } else {
        mediaRecorder.stop();
        button.textContent = 'Start Recording';
    }

    isRecording = !isRecording;
}
