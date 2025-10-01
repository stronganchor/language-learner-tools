(function () {
    'use strict';

    let mediaRecorder = null;
    let audioChunks = [];
    let currentImageIndex = 0;
    let recordingStartTime = 0;
    let timerInterval = null;
    let currentBlob = null;

    const images = window.ll_recorder_data?.images || [];
    const ajaxUrl = window.ll_recorder_data?.ajax_url;
    const nonce = window.ll_recorder_data?.nonce;

    if (images.length === 0) return;

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        setupElements();
        loadImage(0);
        setupEventListeners();
    }

    function setupElements() {
        // Cache DOM elements
        window.llRecorder = {
            image: document.getElementById('ll-current-image'),
            title: document.getElementById('ll-image-title'),
            prompt: document.getElementById('ll-image-prompt'),
            recordBtn: document.getElementById('ll-record-btn'),
            indicator: document.getElementById('ll-recording-indicator'),
            timer: document.getElementById('ll-recording-timer'),
            playbackControls: document.getElementById('ll-playback-controls'),
            playbackAudio: document.getElementById('ll-playback-audio'),
            redoBtn: document.getElementById('ll-redo-btn'),
            submitBtn: document.getElementById('ll-submit-btn'),
            status: document.getElementById('ll-upload-status'),
            currentNum: document.querySelector('.ll-current-num'),
            completeScreen: document.querySelector('.ll-recording-complete'),
            completedCount: document.querySelector('.ll-completed-count'),
            mainScreen: document.querySelector('.ll-recording-main'),
        };
    }

    function setupEventListeners() {
        const el = window.llRecorder;
        el.recordBtn.addEventListener('click', toggleRecording);
        el.redoBtn.addEventListener('click', redo);
        el.submitBtn.addEventListener('click', submitAndNext);
    }

    function loadImage(index) {
        if (index >= images.length) {
            showComplete();
            return;
        }

        currentImageIndex = index;
        const img = images[index];
        const el = window.llRecorder;

        el.image.src = img.image_url;
        el.title.textContent = img.title;
        el.prompt.textContent = `Please record: "${img.title}"`;
        el.currentNum.textContent = index + 1;

        resetRecordingState();
    }

    function resetRecordingState() {
        const el = window.llRecorder;
        el.recordBtn.style.display = 'inline-block';
        el.indicator.style.display = 'none';
        el.playbackControls.style.display = 'none';
        el.status.textContent = '';
        el.status.className = 'll-upload-status';
        currentBlob = null;
        audioChunks = [];
    }

    async function toggleRecording() {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            stopRecording();
        } else {
            await startRecording();
        }
    }

    async function startRecording() {
        const el = window.llRecorder;

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });

            // Use webm with opus codec for good compression
            const options = { mimeType: 'audio/webm;codecs=opus' };
            mediaRecorder = new MediaRecorder(stream, options);

            audioChunks = [];
            mediaRecorder.ondataavailable = (e) => audioChunks.push(e.data);
            mediaRecorder.onstop = handleRecordingStopped;

            mediaRecorder.start();
            recordingStartTime = Date.now();

            el.recordBtn.textContent = 'Stop Recording';
            el.recordBtn.classList.add('recording');
            el.indicator.style.display = 'block';

            timerInterval = setInterval(updateTimer, 100);

        } catch (err) {
            console.error('Error accessing microphone:', err);
            showStatus('Error: Could not access microphone. Please check permissions.', 'error');
        }
    }

    function stopRecording() {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
            mediaRecorder.stream.getTracks().forEach(track => track.stop());
            clearInterval(timerInterval);
        }
    }

    function updateTimer() {
        const elapsed = (Date.now() - recordingStartTime) / 1000;
        const minutes = Math.floor(elapsed / 60);
        const seconds = Math.floor(elapsed % 60);
        window.llRecorder.timer.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
    }

    function handleRecordingStopped() {
        const el = window.llRecorder;

        currentBlob = new Blob(audioChunks, { type: 'audio/webm' });
        const url = URL.createObjectURL(currentBlob);

        el.playbackAudio.src = url;
        el.recordBtn.style.display = 'none';
        el.indicator.style.display = 'none';
        el.playbackControls.style.display = 'block';
    }

    function redo() {
        resetRecordingState();
    }

    async function submitAndNext() {
        if (!currentBlob) return;

        const el = window.llRecorder;
        const img = images[currentImageIndex];

        showStatus('Uploading...', 'uploading');
        el.submitBtn.disabled = true;

        const formData = new FormData();
        formData.append('action', 'll_upload_recording');
        formData.append('nonce', nonce);
        formData.append('image_id', img.id);
        formData.append('audio', currentBlob, `${img.title}.webm`);

        try {
            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const data = await response.json();

            if (data.success) {
                showStatus('Uploaded successfully!', 'success');
                setTimeout(() => {
                    loadImage(currentImageIndex + 1);
                }, 1000);
            } else {
                showStatus('Upload failed: ' + (data.data || 'Unknown error'), 'error');
                el.submitBtn.disabled = false;
            }

        } catch (err) {
            console.error('Upload error:', err);
            showStatus('Upload failed. Please try again.', 'error');
            el.submitBtn.disabled = false;
        }
    }

    function showStatus(message, type) {
        const el = window.llRecorder.status;
        el.textContent = message;
        el.className = 'll-upload-status ' + type;
    }

    function showComplete() {
        const el = window.llRecorder;
        el.mainScreen.style.display = 'none';
        el.completeScreen.style.display = 'block';
        el.completedCount.textContent = images.length;
    }

})();