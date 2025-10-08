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
    const requireAll = !!window.ll_recorder_data?.require_all_types;
    const i18n = window.ll_recorder_data?.i18n || {};

    if (images.length === 0) return;

    const icons = {
        record: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="white"><circle cx="12" cy="12" r="8"/></svg>',
        stop: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="white"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>',
        check: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="white"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>',
        redo: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="white"><path d="M12 5V1L7 6l5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/></svg>',
        skip: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="white"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>',
    };

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        setupElements();
        loadImage(0);
        setupEventListeners();

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

    function setTypeForCurrentImage() {
        const sel = document.getElementById('ll-recording-type');
        if (!sel) return;
        const img = images[currentImageIndex];
        const next = (img && Array.isArray(img.missing_types) && img.missing_types.length)
            ? img.missing_types[0]
            : sel.value;
        if (next && sel.value !== next) sel.value = next;
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

        const hideName = window.ll_recorder_data?.hide_name || false;
        if (hideName) {
            el.title.textContent = '';
            el.title.style.display = 'none';
        } else {
            el.title.textContent = img.title;
            el.title.style.display = '';
        }

        let categoryEl = document.getElementById('ll-image-category');
        if (!categoryEl) {
            categoryEl = document.createElement('p');
            categoryEl.id = 'll-image-category';
            categoryEl.className = 'll-image-category';
            el.title.parentNode.insertBefore(categoryEl, el.title.nextSibling);
        }
        categoryEl.textContent = (i18n.category || 'Category:') + ' ' +
            (img.category_name || i18n.uncategorized || 'Uncategorized');

        setTypeForCurrentImage();
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

            const options = { mimeType: 'audio/wav' };
            if (!MediaRecorder.isTypeSupported('audio/wav')) {
                if (MediaRecorder.isTypeSupported('audio/webm;codecs=pcm')) {
                    options.mimeType = 'audio/webm;codecs=pcm';
                } else {
                    options.mimeType = 'audio/webm';
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
            showStatus(i18n.microphone_error || 'Error: Could not access microphone', 'error');
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

        el.playbackAudio.play().catch(err => {
            console.log('Auto-play prevented by browser:', err);
        });
    }

    function redo() {
        resetRecordingState();
    }

    function skipToNext() {
        if (!requireAll) {
            loadImage(currentImageIndex + 1);
            return;
        }
        const sel = document.getElementById('ll-recording-type');
        const img = images[currentImageIndex];
        if (!img || !Array.isArray(img.missing_types) || img.missing_types.length === 0) {
            loadImage(currentImageIndex + 1);
            return;
        }
        const curType = (sel && sel.value) || img.missing_types[0];
        img.missing_types = img.missing_types.filter(t => t !== curType);

        if (img.missing_types.length > 0) {
            setTypeForCurrentImage();
            resetRecordingState();
            showStatus(i18n.skipped_type || 'Skipped this type. Next type selected.', 'info');
        } else {
            loadImage(currentImageIndex + 1);
        }
    }

    async function submitAndNext() {
        if (!currentBlob) {
            console.error(i18n.no_blob || 'No audio blob to submit');
            return;
        }

        const el = window.llRecorder;
        const img = images[currentImageIndex];

        const recordingType = document.getElementById('ll-recording-type')?.value || 'isolation';

        const wordsetIds = (window.ll_recorder_data && Array.isArray(window.ll_recorder_data.wordset_ids))
            ? window.ll_recorder_data.wordset_ids
            : [];
        const wordsetLegacy = window.ll_recorder_data?.wordset || '';

        console.log((i18n.starting_upload || 'Starting upload for image:'), img.id, img.title);

        showStatus(i18n.uploading || 'Uploading...', 'uploading');
        el.submitBtn.disabled = true;
        el.redoBtn.disabled = true;
        el.skipBtn.disabled = true;

        const formData = new FormData();
        formData.append('action', 'll_upload_recording');
        formData.append('nonce', window.ll_recorder_data?.nonce);
        formData.append('image_id', img.id);
        formData.append('recording_type', recordingType);

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
                const errorMsg = i18n.http_error
                    ? i18n.http_error.replace('%d', response.status).replace('%s', response.statusText)
                    : `HTTP ${response.status}: ${response.statusText}`;
                throw new Error(errorMsg);
            }

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text.substring(0, 500));
                throw new Error(i18n.invalid_response || 'Server returned invalid response format');
            }

            const data = await response.json();
            console.log('Upload response:', data);

            if (data.success) {
                const remaining = (data.data && Array.isArray(data.data.remaining_types)) ? data.data.remaining_types : [];

                if (requireAll && remaining.length > 0) {
                    images[currentImageIndex].missing_types = remaining.slice();
                    images[currentImageIndex].existing_types = Array.isArray(images[currentImageIndex].existing_types)
                        ? images[currentImageIndex].existing_types
                        : [];
                    if (recordingType && !images[currentImageIndex].existing_types.includes(recordingType)) {
                        images[currentImageIndex].existing_types.push(recordingType);
                    }

                    resetRecordingState();
                    setTypeForCurrentImage();
                    const savedMsg = i18n.saved_type
                        ? i18n.saved_type.replace('%s', recordingType)
                        : `Saved ${recordingType}. Next type selected.`;
                    showStatus(savedMsg, 'success');
                    return;
                }

                showStatus(i18n.success || 'Success! Recording will be processed later.', 'success');
                setTimeout(() => {
                    loadImage(currentImageIndex + 1);
                }, 800);
            } else {
                const errorMsg = data.data || data.message || 'Unknown error';
                console.error('Upload failed:', errorMsg);
                showStatus((i18n.error_prefix || 'Error:') + ' ' + errorMsg, 'error');

                el.submitBtn.disabled = false;
                el.redoBtn.disabled = false;
                el.skipBtn.disabled = false;
            }

        } catch (err) {
            console.error('Upload error:', err);
            const failMsg = (i18n.upload_failed || 'Upload failed:') + ' ' + err.message;
            showStatus(failMsg, 'error');

            el.submitBtn.disabled = false;
            el.redoBtn.disabled = false;
            el.skipBtn.disabled = false;
        }
    }

    function showComplete() {
        const el = window.llRecorder;
        if (el.mainScreen) el.mainScreen.style.display = 'none';
        if (el.completeScreen) {
            el.completeScreen.style.display = 'block';
            if (el.completedCount) el.completedCount.textContent = String(images.length);
        } else {
            const p = document.createElement('p');
            p.textContent = i18n.all_complete || 'All recordings completed for the selected set. Thank you!';
            document.querySelector('.ll-recording-wrapper')?.appendChild(p);
        }
    }

})();