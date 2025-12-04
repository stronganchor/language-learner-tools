(function () {
    'use strict';

    let mediaRecorder = null;
    let audioChunks = [];
    let currentImageIndex = 0;
    let exhaustedCategories = new Set();
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
        setupCategorySelector();
        loadImage(0);
        setupEventListeners();

        if (window.llRecorder && window.llRecorder.recordBtn) {
            window.llRecorder.recordBtn.innerHTML = icons.record;
        }
    }

    function setupElements() {
        window.llRecorder = {
            image: document.getElementById('ll-current-image'),
            imageContainer: document.querySelector('.ll-recording-image-container'),
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
            totalNum: document.querySelector('.ll-total-num'),
            completeScreen: document.querySelector('.ll-recording-complete'),
            completedCount: document.querySelector('.ll-completed-count'),
            mainScreen: document.querySelector('.ll-recording-main'),
            categorySelect: document.getElementById('ll-category-select'),
            recordingTypeSelect: document.getElementById('ll-recording-type'),
        };
    }

    function setupCategorySelector() {
        const el = window.llRecorder;
        if (el.categorySelect) {
            el.categorySelect.addEventListener('change', switchCategory);
        }
    }

    function decodeEntities(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.innerHTML = text;
        return div.textContent || div.innerText || '';
    }

    // Fit text inside its container for text-only cards
    function fitTextToContainer(el) {
        if (!el) return;
        const card = el.closest('.flashcard-container');
        if (!card) return;

        // Match quiz sizing: measure available box and shrink until it fits
        const boxH = Math.max(1, card.clientHeight - 15);
        const boxW = Math.max(1, card.clientWidth - 15);
        const fontFamily = (window.LLFlashcards && LLFlashcards.Util && typeof LLFlashcards.Util.measureTextWidth === 'function')
            ? null
            : (getComputedStyle(el).fontFamily || 'sans-serif');

        const measureWidth = (text, fs) => {
            if (window.LLFlashcards && LLFlashcards.Util && typeof LLFlashcards.Util.measureTextWidth === 'function') {
                return LLFlashcards.Util.measureTextWidth(text, fs + 'px ' + (fontFamily || getComputedStyle(el).fontFamily || 'sans-serif'));
            }
            // Fallback: approximate using a hidden clone
            const clone = el.cloneNode(true);
            clone.style.visibility = 'hidden';
            clone.style.position = 'absolute';
            clone.style.fontSize = fs + 'px';
            clone.style.lineHeight = fs + 'px';
            clone.style.maxWidth = boxW + 'px';
            clone.style.width = 'auto';
            card.appendChild(clone);
            const w = clone.scrollWidth;
            card.removeChild(clone);
            return w;
        };

        const text = el.textContent || '';
        const maxSize = 56;
        const minSize = 12;
        for (let fs = maxSize; fs >= minSize; fs--) {
            const w = measureWidth(text, fs);
            if (w > boxW) continue;
            el.style.fontSize = fs + 'px';
            el.style.lineHeight = fs + 'px';
            el.style.maxWidth = boxW + 'px';
            el.style.whiteSpace = 'normal';
            el.style.wordBreak = 'break-word';
            if (el.scrollHeight <= boxH) {
                break;
            }
        }
    }

    function setTypeForCurrentImage() {
        const el = window.llRecorder;
        if (!el.recordingTypeSelect) return;

        const img = images[currentImageIndex];
        const next = (img && Array.isArray(img.missing_types) && img.missing_types.length)
            ? img.missing_types[0]
            : el.recordingTypeSelect.value;

        if (!next) return;

        // Ensure the option exists; if not, create it so selection always shows
        let opt = Array.from(el.recordingTypeSelect.options).find(o => o.value === next);
        if (!opt) {
            opt = document.createElement('option');
            opt.value = next;
            // Try to show a nice label if we have it in localized data; fallback to slug
            const term = (window.ll_recorder_data?.recording_types || []).find(t => t.slug === next);
            opt.textContent = term?.name || next.charAt(0).toUpperCase() + next.slice(1);
            el.recordingTypeSelect.appendChild(opt);
        }

        if (el.recordingTypeSelect.value !== next) {
            el.recordingTypeSelect.value = next;
            // Some themes/polyfills need a change event to redraw the visible part
            el.recordingTypeSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function showStatus(message, type) {
        const el = window.llRecorder;
        if (!el || !el.status) return;
        el.status.textContent = message;
        el.status.className = 'll-upload-status';
        if (type) el.status.classList.add(type);
    }

    function handleSuccessfulUpload(recordingType, remaining) {
        if (!Array.isArray(images[currentImageIndex].existing_types)) {
            images[currentImageIndex].existing_types = [];
        }
        if (recordingType && !images[currentImageIndex].existing_types.includes(recordingType)) {
            images[currentImageIndex].existing_types.push(recordingType);
        }
        images[currentImageIndex].missing_types = remaining.slice();

        if (requireAll && remaining.length > 0) {
            setTypeForCurrentImage();
            resetRecordingState();
            showStatus(i18n.saved_next_type || 'Saved. Next type selected.', 'success');
            return true; // Signal that we're staying on this image
        }

        showStatus(i18n.success || 'Success! Recording will be processed later.', 'success');
        setTimeout(() => loadImage(currentImageIndex + 1), 800);
        return false; // Signal that we're moving to next image
    }

    function setupEventListeners() {
        const el = window.llRecorder;
        el.recordBtn.addEventListener('click', toggleRecording);
        el.redoBtn.addEventListener('click', redo);
        el.submitBtn.addEventListener('click', submitAndNext);
        el.skipBtn.addEventListener('click', skipToNext);
    }

    // Proactively surface likely microphone issues so users know what to fix
    document.addEventListener('DOMContentLoaded', () => {
        preflightMicCheck().catch(() => { /* ignore */ });
    });

    async function preflightMicCheck() {
        // Only run if the UI exists
        if (!window.llRecorder || !window.llRecorder.status) return;

        // Insecure context cannot access mic; advise immediately
        if (!window.isSecureContext) {
            showStatus((i18n.insecure_context || 'Microphone requires a secure connection. Please use HTTPS or localhost.'), 'error');
            return;
        }

        // If Permissions API is available, warn if previously denied
        try {
            if (navigator.permissions && navigator.permissions.query) {
                const p = await navigator.permissions.query({ name: 'microphone' });
                if (p && p.state === 'denied') {
                    showStatus(
                        (i18n.mic_permission_blocked || 'Microphone permission is blocked for this site. Click the lock icon in the address bar → Site settings → allow Microphone, then reload.'),
                        'error'
                    );
                    return;
                }
            }
        } catch (_) { /* ignore */ }

        // If no audio inputs are detected, surface that
        try {
            if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
                const devices = await navigator.mediaDevices.enumerateDevices();
                const inputs = devices.filter(d => d.kind === 'audioinput');
                if (inputs.length === 0) {
                    showStatus((i18n.no_mic_devices || 'No microphone input devices detected. Check Windows microphone privacy and Sound settings.'), 'error');
                }
            }
        } catch (_) { /* ignore */ }
    }

    function loadImage(index) {
        if (index >= images.length) {
            showComplete();
            return;
        }

        currentImageIndex = index;
        const img = images[index];
        const el = window.llRecorder;

        el.currentNum.textContent = index + 1;
        el.totalNum.textContent = images.length;

        if (img.is_text_only) {
            el.image.style.display = 'none';
            if (!el.textDisplay) {
                el.textDisplay = document.createElement('div');
                el.textDisplay.className = 'quiz-text ll-text-display';
                const card = el.imageContainer.querySelector('.flashcard-container');
                card.classList.add('text-based');
                card.appendChild(el.textDisplay);
            }
            el.textDisplay.style.display = 'flex';
            el.imageContainer.querySelector('.flashcard-container').classList.add('text-based');
        } else {
            if (el.textDisplay) {
                el.textDisplay.style.display = 'none';
            }
            el.image.style.display = 'block';
            el.image.src = img.image_url;
            const card = el.imageContainer.querySelector('.flashcard-container');
            card.classList.remove('text-based');
        }

        // NEW: Prefer word title over image title if available and matched
        let displayTitle = decodeEntities(img.title); // Default to image title
        const targetLang = window.ll_recorder_data?.language || 'TR'; // From localized data, e.g., 'TR'
        if (img.use_word_display && img.word_title) {
            displayTitle = decodeEntities(img.word_title); // Prefer word's target lang title
        } else if (img.use_word_display && img.word_translation && img.word_translation.toLowerCase().includes(targetLang.toLowerCase())) {
            displayTitle = decodeEntities(img.word_translation); // Use word's translation if it's in target lang
        }

        el.title.textContent = displayTitle;
        if (img.is_text_only && el.textDisplay) {
            el.textDisplay.textContent = displayTitle;
            fitTextToContainer(el.textDisplay);
        }
        if (!img.is_text_only) {
            // Remove text styling so image cards match quiz styling
            const card = el.imageContainer.querySelector('.flashcard-container');
            card.classList.remove('text-based');
            if (el.textDisplay) {
                el.textDisplay.textContent = '';
            }
        }
        const hideName = window.ll_recorder_data?.hide_name || false;
        if (hideName) {
            el.title.style.display = 'none';
        } else {
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

            // Prioritize formats by audio processing quality
            // Avoid Opus (generic webm) - causes issues with noise reduction/processing
            const options = {};

            // Best: uncompressed WAV (lossless, ideal for processing)
            if (MediaRecorder.isTypeSupported('audio/wav')) {
                options.mimeType = 'audio/wav';
            }
            // Second best: WebM with PCM (lossless)
            else if (MediaRecorder.isTypeSupported('audio/webm;codecs=pcm')) {
                options.mimeType = 'audio/webm;codecs=pcm';
            }
            // Good: MP3 (lossy but mature codec, excellent tool support)
            else if (MediaRecorder.isTypeSupported('audio/mpeg') || MediaRecorder.isTypeSupported('audio/mp3')) {
                options.mimeType = MediaRecorder.isTypeSupported('audio/mpeg') ? 'audio/mpeg' : 'audio/mp3';
            }
            // iOS fallback: MP4 (usually AAC - lossy but good processing support)
            else if (MediaRecorder.isTypeSupported('audio/mp4')) {
                options.mimeType = 'audio/mp4';
            }
            // iOS alternate: AAC (good processing support)
            else if (MediaRecorder.isTypeSupported('audio/aac')) {
                options.mimeType = 'audio/aac';
            }
            // Last resort: allow Opus/webm so we record something instead of failing silently
            else if (MediaRecorder.isTypeSupported('audio/webm')) {
                options.mimeType = 'audio/webm';
                console.warn('Falling back to audio/webm (Opus); processing quality may be lower.');
            }
            else {
                console.error('No suitable audio format supported by this browser');
                throw new Error('Browser does not support required audio formats for recording');
            }

            console.log('Using MIME type:', options.mimeType);

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
            console.error('Error name:', err?.name);
            console.error('Error message:', err?.message);

            const message = await buildMicErrorMessage(err);
            showStatus(message, 'error');
            // Make sure UI remains usable after failure
            el.recordBtn.disabled = false;
            el.skipBtn.disabled = false;
        }
    }

    async function buildMicErrorMessage(err) {
        const name = err && err.name ? String(err.name) : '';
        const isSecure = !!window.isSecureContext;

        let permState = 'unknown';
        try {
            if (navigator.permissions && navigator.permissions.query) {
                const p = await navigator.permissions.query({ name: 'microphone' });
                permState = p?.state || 'unknown';
            }
        } catch (_) { /* ignore */ }

        let inputCount = -1;
        try {
            if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
                const devices = await navigator.mediaDevices.enumerateDevices();
                inputCount = devices.filter(d => d.kind === 'audioinput').length;
            }
        } catch (_) { /* ignore */ }

        // Default fallback
        let msg = i18n.microphone_error || 'Error: Could not access microphone';

        // Insecure context
        if (!isSecure) {
            return 'Microphone requires a secure connection. Open this page over HTTPS or localhost and try again.';
        }

        // Tailored messages by error name
        switch (name) {
            case 'NotAllowedError':
            case 'PermissionDeniedError':
                if (permState === 'denied') {
                    msg = 'Microphone permission is blocked for this site. Click the lock icon → Site settings → set Microphone to Allow, then reload the page.';
                } else {
                    msg = 'Microphone access was not granted. If no browser prompt appears, open Site settings from the lock icon and allow Microphone for this site, then reload.';
                }
                break;
            case 'NotReadableError':
                msg = 'Microphone is in use by another app or blocked by the OS. Close apps that use the mic (Zoom/Teams/Discord), then try again.';
                break;
            case 'NotFoundError':
            case 'DevicesNotFoundError':
                msg = 'No microphone found. Connect a microphone and check Windows Privacy & Sound settings.';
                break;
            case 'OverconstrainedError':
            case 'ConstraintNotSatisfiedError':
                msg = 'The selected microphone doesn’t meet the requested constraints. Set your default input device in system settings and try again.';
                break;
            case 'SecurityError':
                msg = 'Browser blocked access due to security policy. Ensure you are on HTTPS and try again.';
                break;
            case 'AbortError':
                msg = 'Audio capture aborted unexpectedly. Try again.';
                break;
            default:
                // Keep default
                break;
        }

        // Augment with quick Windows/Chrome hints
        const hints = [];
        if (permState === 'denied') {
            hints.push('Windows/Chrome: Click the lock icon → Site settings → Microphone: Allow.');
        }
        if (inputCount === 0) {
            hints.push('No input devices detected: Windows Settings → Privacy & Security → Microphone → allow apps and desktop apps; then check Sound settings for the default input.');
        }

        if (hints.length) {
            msg += ' ' + hints.join(' ');
        }

        return msg;
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
        el.recordBtn.disabled = true;
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

    async function skipToNext() {
        if (!requireAll) {
            loadImage(currentImageIndex + 1);
            return;
        }
        const el = window.llRecorder;
        if (!el.recordingTypeSelect) return;

        const img = images[currentImageIndex];
        if (!img || !Array.isArray(img.missing_types) || img.missing_types.length === 0) {
            loadImage(currentImageIndex + 1);
            return;
        }

        const curType = el.recordingTypeSelect.value;
        if (!curType) return;

        // Remove the current type for this session only
        images[currentImageIndex].missing_types = img.missing_types.filter(t => t !== curType);

        if (images[currentImageIndex].missing_types.length > 0) {
            setTypeForCurrentImage();
            resetRecordingState();
            showStatus(i18n.skipped_type || 'Skipped this type. Next type selected.', 'info');
        } else {
            loadImage(currentImageIndex + 1);
        }
    }

    async function submitAndNext() {
        if (!currentBlob) {
            const msg = i18n.no_blob || 'No audio captured. Please record before saving.';
            console.error(msg);
            showStatus(msg, 'error');
            return;
        }

        const el = window.llRecorder;
        const img = images[currentImageIndex];
        const recordingType = el.recordingTypeSelect?.value || 'isolation';

        const wordsetIds = Array.isArray(window.ll_recorder_data?.wordset_ids) ? window.ll_recorder_data.wordset_ids : [];
        const wordsetLegacy = window.ll_recorder_data?.wordset || '';
        const includeTypes = window.ll_recorder_data?.include_types || '';
        const excludeTypes = window.ll_recorder_data?.exclude_types || '';
        const activeCategory = el.categorySelect?.value || '';

        showStatus(i18n.uploading || 'Uploading...', 'uploading');
        el.submitBtn.disabled = true;
        el.redoBtn.disabled = true;
        el.skipBtn.disabled = false;

        const formData = new FormData();
        formData.append('action', 'll_upload_recording');
        formData.append('nonce', nonce);
        formData.append('image_id', img.id || 0);
        formData.append('word_id', img.word_id || 0);
        formData.append('recording_type', recordingType);
        formData.append('wordset_ids', JSON.stringify(wordsetIds));
        formData.append('wordset', wordsetLegacy);
        formData.append('include_types', includeTypes);
        formData.append('exclude_types', excludeTypes);
        formData.append('word_title', img.word_title || img.title || '');
        formData.append('active_category', activeCategory);

        // Extension detection - prioritize quality formats
        let extension = '.wav';
        const blobType = currentBlob.type.toLowerCase();
        if (blobType.includes('wav')) {
            extension = '.wav';
        } else if (blobType.includes('pcm')) {
            extension = '.wav';  // PCM is essentially WAV data
        } else if (blobType.includes('mpeg') || blobType.includes('mp3')) {
            extension = '.mp3';
        } else if (blobType.includes('mp4')) {
            extension = '.mp4';
        } else if (blobType.includes('aac')) {
            extension = '.aac';
        } else {
            // Shouldn't reach here based on our format selection
            console.warn('Unexpected blob type:', currentBlob.type);
            extension = '.webm';  // Fallback just in case
        }

        console.log('Blob type:', currentBlob.type, 'Extension:', extension);
        formData.append('audio', currentBlob, `${img.title}${extension}`);

        try {
            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });

            let data;
            const contentType = response.headers.get('content-type') || '';
            if (response.ok && contentType.includes('application/json')) {
                data = await response.json();
            } else {
                return await verifyAfterError({ img, recordingType, wordsetIds, wordsetLegacy, includeTypes, excludeTypes });
            }

            if (data.success) {
                const remaining = Array.isArray(data.data?.remaining_types) ? data.data.remaining_types : [];
                handleSuccessfulUpload(recordingType, remaining);
            } else {
                await verifyAfterError({ img, recordingType, wordsetIds, wordsetLegacy, includeTypes, excludeTypes });
            }
        } catch (err) {
            await verifyAfterError({ img, recordingType, wordsetIds, wordsetLegacy, includeTypes, excludeTypes });
        }
    }

    async function verifyAfterError({ img, recordingType, wordsetIds, wordsetLegacy, includeTypes, excludeTypes }) {
        const activeCategory = window.llRecorder?.categorySelect?.value || '';
        try {
            const fd = new FormData();
            fd.append('action', 'll_verify_recording');
            fd.append('nonce', nonce);
            fd.append('image_id', img.id || 0);
            fd.append('word_id', img.word_id || 0);
            fd.append('recording_type', recordingType);
            fd.append('wordset_ids', JSON.stringify(wordsetIds));
            fd.append('wordset', wordsetLegacy);
            fd.append('include_types', includeTypes);
            fd.append('exclude_types', excludeTypes);
            fd.append('word_title', img.word_title || img.title || '');
            fd.append('active_category', activeCategory);

            const verifyResp = await fetch(ajaxUrl, { method: 'POST', body: fd });
            if (!verifyResp.ok) throw new Error(`HTTP ${verifyResp.status}: ${verifyResp.statusText}`);
            const verifyData = await verifyResp.json();

            if (verifyData?.success && verifyData.data?.found_audio_post_id) {
                const remaining = Array.isArray(verifyData.data.remaining_types) ? verifyData.data.remaining_types : [];
                handleSuccessfulUpload(recordingType, remaining);
                return;
            }

            // Really not there — show error
            showStatus((i18n.upload_failed || 'Upload failed:') + ' HTTP 500 (no recording found)', 'error');
        } catch (e) {
            console.error('Verify error:', e);
            showStatus((i18n.upload_failed || 'Upload failed:') + ' ' + (e.message || 'Verify failed'), 'error');
        } finally {
            const el = window.llRecorder;
            el.submitBtn.disabled = false;
            el.redoBtn.disabled = false;
        }
    }

    async function switchCategory() {
        const el = window.llRecorder;
        if (!el.categorySelect) return;

        const newCategory = el.categorySelect.value;
        if (!newCategory) return;

        showStatus(i18n.switching_category || 'Switching category...', 'info');
        el.categorySelect.disabled = true;
        el.recordBtn.disabled = true;
        el.skipBtn.disabled = true;

        const formData = new FormData();
        formData.append('action', 'll_get_images_for_recording');
        formData.append('nonce', nonce);
        formData.append('category', newCategory);
        formData.append('wordset_ids', JSON.stringify(window.ll_recorder_data?.wordset_ids || []));
        formData.append('include_types', window.ll_recorder_data?.include_types || '');
        formData.append('exclude_types', window.ll_recorder_data?.exclude_types || '');

        try {
            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error(i18n.invalid_response || 'Server returned invalid response format');
            }

            const data = await response.json();

            if (data.success) {
                const newImages = data.data?.images || [];
                if (newImages.length === 0) {
                    // Mark this category as exhausted
                    exhaustedCategories.add(newCategory);

                    // This category has no images - try the next one
                    const nextCategory = getNextCategoryAfter(newCategory);
                    if (nextCategory && nextCategory.slug !== newCategory) {
                        el.categorySelect.value = nextCategory.slug;
                        el.categorySelect.disabled = false;
                        el.recordBtn.disabled = false;
                        el.skipBtn.disabled = false;
                        // Recursively try the next category
                        return switchCategory();
                    } else {
                        // No more categories to try
                        showStatus(i18n.no_images_in_category || 'No images need audio in any remaining category.', 'error');
                        el.categorySelect.disabled = false;
                        return;
                    }
                }

                // Update global images and reset indexer
                window.ll_recorder_data.images = newImages;
                images.length = 0;
                images.push(...newImages);
                currentImageIndex = 0;

                // Update recording type dropdown options
                const newTypes = data.data?.recording_types || [];
                el.recordingTypeSelect.innerHTML = '';
                newTypes.forEach(type => {
                    const option = document.createElement('option');
                    option.value = type.slug;
                    option.textContent = type.name || type.slug;
                    el.recordingTypeSelect.appendChild(option);
                });

                // Reset and load first image
                if (el.completeScreen) el.completeScreen.style.display = 'none';
                if (el.mainScreen) el.mainScreen.style.display = 'flex';
                loadImage(0);

                showStatus(i18n.category_switched || 'Category switched. Ready to record.', 'success');
            } else {
                const errorMsg = data.data || data.message || 'Switch failed';
                showStatus((i18n.switch_failed || 'Switch failed:') + ' ' + errorMsg, 'error');
            }
        } catch (err) {
            console.error('Category switch error:', err);
            showStatus((i18n.switch_failed || 'Switch failed:') + ' ' + err.message, 'error');
        } finally {
            el.categorySelect.disabled = false;
            el.recordBtn.disabled = false;
            el.skipBtn.disabled = false;
        }
    }

    // Add this new helper function after getNextCategory()
    function getNextCategoryAfter(currentSlug) {
        const el = window.llRecorder;
        if (!el.categorySelect) return null;

        const availableCategories = window.ll_recorder_data?.available_categories || {};
        const categoryEntries = Object.entries(availableCategories);

        if (categoryEntries.length <= 1) return null;

        const currentIndex = categoryEntries.findIndex(([slug]) => slug === currentSlug);

        if (currentIndex === -1) return null;

        // Get next category, or wrap to first if at end
        const nextIndex = (currentIndex + 1) % categoryEntries.length;
        const [slug, name] = categoryEntries[nextIndex];

        return { slug, name };
    }

    function showComplete() {
        const el = window.llRecorder;
        if (el.mainScreen) el.mainScreen.style.display = 'none';

        // Mark current category as exhausted since we completed all its images
        const currentCategory = el.categorySelect?.value;
        if (currentCategory) {
            exhaustedCategories.add(currentCategory);
        }

        if (el.completeScreen) {
            el.completeScreen.style.display = 'block';
            if (el.completedCount) el.completedCount.textContent = images.length;

            // Add next category button if there are other categories
            const nextCategory = getNextCategory();
            let nextCategoryBtn = el.completeScreen.querySelector('.ll-next-category-btn');

            if (nextCategory) {
                if (!nextCategoryBtn) {
                    nextCategoryBtn = document.createElement('button');
                    nextCategoryBtn.className = 'll-btn ll-btn-primary ll-next-category-btn';
                    nextCategoryBtn.style.marginTop = '20px';
                    nextCategoryBtn.innerHTML = `
                <span>${nextCategory.name}</span>
                <svg viewBox="0 0 24 24" style="width: 24px; height: 24px; margin-left: 8px;">
                    <path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z" fill="currentColor"/>
                </svg>
            `;
                    el.completeScreen.querySelector('p').insertAdjacentElement('afterend', nextCategoryBtn);

                    nextCategoryBtn.addEventListener('click', () => {
                        if (el.categorySelect) {
                            el.categorySelect.value = nextCategory.slug;
                            el.categorySelect.dispatchEvent(new Event('change'));
                        }
                    });
                } else {
                    // Update existing button with new category
                    nextCategoryBtn.innerHTML = `
                <span>${nextCategory.name}</span>
                <svg viewBox="0 0 24 24" style="width: 24px; height: 24px; margin-left: 8px;">
                    <path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z" fill="currentColor"/>
                </svg>
            `;
                }
            } else if (nextCategoryBtn) {
                // Remove button if no next category
                nextCategoryBtn.remove();
            }
        } else {
            const p = document.createElement('p');
            p.textContent = i18n.all_complete || 'All recordings completed for the selected set. Thank you!';
            document.querySelector('.ll-recording-wrapper')?.appendChild(p);
        }
    }

    function getNextCategory() {
        const el = window.llRecorder;
        if (!el.categorySelect) return null;

        const availableCategories = window.ll_recorder_data?.available_categories || {};
        const categoryEntries = Object.entries(availableCategories);

        if (categoryEntries.length <= 1) return null;

        const currentCategory = el.categorySelect.value;
        const currentIndex = categoryEntries.findIndex(([slug]) => slug === currentCategory);

        if (currentIndex === -1) return null;

        // Search for next non-exhausted category
        let attempts = 0;
        let nextIndex = currentIndex;

        while (attempts < categoryEntries.length) {
            nextIndex = (nextIndex + 1) % categoryEntries.length;
            const [slug, name] = categoryEntries[nextIndex];

            // Skip if this is the current category or if it's exhausted
            if (slug !== currentCategory && !exhaustedCategories.has(slug)) {
                return { slug, name };
            }

            attempts++;
        }

        // All categories are exhausted
        return null;
    }

})();
