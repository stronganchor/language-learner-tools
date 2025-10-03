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

    // SVG icons
    const icons = {
        record: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/></svg>',
        stop: '<svg viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>',
        check: '<svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>',
        redo: '<svg viewBox="0 0 24 24"><path d="M12 5V1L7 6l5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/></svg>',
        skip: '<svg viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>',
    };

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        setupElements();
        loadImage(0);
        setupEventListeners();

        // Set initial record icon
        if (window.llRecorder && window.llRecorder.recordBtn) {
            window.llRecorder.recordBtn.innerHTML = icons.record;
        }
    }

    function setupElements() {
        window.llRecorder = {
            image: document.getElementById('ll-current-image'),
            title: document.getElementById('ll-image-title'),
            recordBtn: document.getElementById('ll-record-btn'),
            indicator: document.getElementById('ll-recording-indicator'),
            timer: document.getElementById('ll-recording-timer'),
            playbackControls: document.getElementById('ll-playback-controls'),
            playbackAudio: document.getElementById('ll-playback-audio'),
            redoBtn: document.getElementById('ll-redo-btn'),
            submitBtn: document.getElementById('ll-submit-btn'),
            skipBtn: document.getElementById('ll-skip-btn'),
            status: document.getElementById('ll-upload-status'),
            currentNum: document.querySelector('.ll-current-num'),
            completeScreen: document.querySelector('.ll-recording-complete'),
            completedCount: document.querySelector('.ll-completed-count'),
            mainScreen: document.querySelector('.ll-recording-main'),
        };
    }

    function showStatus(message, type) {
        const el = window.llRecorder;
        if (!el || !el.status) return;
        el.status.textContent = message;
        el.status.className = 'll-upload-status';
        if (type) el.status.classList.add(type);
    }

    function setupEventListeners() {
        const el = window.llRecorder;
        el.recordBtn.addEventListener('click', toggleRecording);
        el.redoBtn.addEventListener('click', redo);
        el.submitBtn.addEventListener('click', submitAndNext);
        el.skipBtn.addEventListener('click', skipToNext);
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
        el.currentNum.textContent = index + 1;

        // Conditionally show/hide the title based on hide_name setting
        const hideName = window.ll_recorder_data?.hide_name || false;
        if (hideName) {
            el.title.textContent = '';
            el.title.style.display = 'none';
        } else {
            el.title.textContent = img.title;
            el.title.style.display = '';
        }

        // Display category name
        let categoryEl = document.getElementById('ll-image-category');
        if (!categoryEl) {
            categoryEl = document.createElement('p');
            categoryEl.id = 'll-image-category';
            categoryEl.className = 'll-image-category';
            el.title.parentNode.insertBefore(categoryEl, el.title.nextSibling);
        }
        categoryEl.textContent = 'Category: ' + (img.category_name || 'Uncategorized');

        resetRecordingState();
    }

    function resetRecordingState() {
        const el = window.llRecorder;
        el.recordBtn.style.display = 'inline-flex';
        el.recordBtn.innerHTML = icons.record;
        el.recordBtn.classList.remove('recording');
        el.recordBtn.disabled = false;
        el.skipBtn.disabled = false;
        el.redoBtn.disabled = false;
        el.submitBtn.disabled = false;
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

            // Use WAV-compatible format for better editing
            const options = { mimeType: 'audio/wav' };
            // Fallback for browsers that don't support audio/wav directly
            if (!MediaRecorder.isTypeSupported('audio/wav')) {
                // Use uncompressed audio - we'll handle format client-side
                if (MediaRecorder.isTypeSupported('audio/webm;codecs=pcm')) {
                    options.mimeType = 'audio/webm;codecs=pcm';
                } else {
                    options.mimeType = 'audio/webm'; // Last resort, but mark for processing
                }
            }

            mediaRecorder = new MediaRecorder(stream, options);

            audioChunks = [];
            mediaRecorder.ondataavailable = (e) => audioChunks.push(e.data);
            mediaRecorder.onstop = handleRecordingStopped;

            mediaRecorder.start();
            recordingStartTime = Date.now();

            el.recordBtn.innerHTML = icons.stop;
            el.recordBtn.classList.add('recording');
            el.indicator.style.display = 'block';
            el.skipBtn.disabled = true;

            timerInterval = setInterval(updateTimer, 100);

        } catch (err) {
            console.error('Error accessing microphone:', err);
            showStatus('Error: Could not access microphone', 'error');
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

        // Create blob with appropriate MIME type
        const mimeType = mediaRecorder.mimeType || 'audio/webm';
        currentBlob = new Blob(audioChunks, { type: mimeType });
        const url = URL.createObjectURL(currentBlob);

        el.playbackAudio.src = url;
        el.recordBtn.style.display = 'none';
        el.indicator.style.display = 'none';
        el.playbackControls.style.display = 'block';
        el.skipBtn.disabled = false;

        el.redoBtn.innerHTML = icons.redo;
        el.submitBtn.innerHTML = icons.check;

        // Auto-play the recording
        el.playbackAudio.play().catch(err => {
            console.log('Auto-play prevented by browser:', err);
        });
    }

    function redo() {
        resetRecordingState();
    }

    function skipToNext() {
        loadImage(currentImageIndex + 1);
    }

    async function submitAndNext() {
        if (!currentBlob) {
            console.error('No audio blob to submit');
            return;
        }

        const el = window.llRecorder;
        const img = images[currentImageIndex];

        // Get recording type (no speaker name needed - handled server-side via current user)
        const recordingType = document.getElementById('ll-recording-type')?.value || 'isolation';

        const wordsetIds = (window.ll_recorder_data && Array.isArray(window.ll_recorder_data.wordset_ids))
            ? window.ll_recorder_data.wordset_ids
            : [];
        const wordsetLegacy = window.ll_recorder_data?.wordset || '';

        console.log('Starting upload for image:', img.id, img.title);

        showStatus('Uploading...', 'uploading');
        el.submitBtn.disabled = true;
        el.redoBtn.disabled = true;
        el.skipBtn.disabled = true;

        const formData = new FormData();
        formData.append('action', 'll_upload_recording');
        formData.append('nonce', window.ll_recorder_data?.nonce);
        formData.append('image_id', img.id);
        formData.append('recording_type', recordingType);

        // Determine file extension from blob type
        let extension = '.webm';
        if (currentBlob.type.includes('wav')) {
            extension = '.wav';
        } else if (currentBlob.type.includes('mp3')) {
            extension = '.mp3';
        } else if (currentBlob.type.includes('pcm')) {
            extension = '.wav';
        }

        formData.append('audio', currentBlob, `${img.title}${extension}`);
        formData.append('wordset_ids', JSON.stringify(wordsetIds));
        formData.append('wordset', wordsetLegacy);

        try {
            const response = await fetch(window.ll_recorder_data?.ajax_url, {
                method: 'POST',
                body: formData,
            });

            console.log('Response status:', response.status);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text.substring(0, 500));
                throw new Error('Server returned invalid response format');
            }

            const data = await response.json();
            console.log('Upload response:', data);

            if (data.success) {
                showStatus('Success! Recording will be processed later.', 'success');
                setTimeout(() => {
                    loadImage(currentImageIndex + 1);
                }, 800);
            } else {
                const errorMsg = data.data || data.message || 'Unknown error';
                console.error('Upload failed:', errorMsg);
                showStatus('Error: ' + errorMsg, 'error');

                el.submitBtn.disabled = false;
                el.redoBtn.disabled = false;
                el.skipBtn.disabled = false;
            }

        } catch (err) {
            console.error('Upload error:', err);
            showStatus('Upload failed: ' + err.message, 'error');

            el.submitBtn.disabled = false;
            el.redoBtn.disabled = false;
            el.skipBtn.disabled = false;
        }
    }

})();