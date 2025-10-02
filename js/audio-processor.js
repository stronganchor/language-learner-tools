(function () {
    'use strict';

    const state = {
        recordings: [],
        selected: new Set(),
        processing: false,
        audioContext: null
    };

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        if (!window.llAudioProcessor || !window.llAudioProcessor.recordings) {
            return;
        }

        state.recordings = window.llAudioProcessor.recordings;
        state.audioContext = new (window.AudioContext || window.webkitAudioContext)();

        wireEventListeners();
        updateSelectedCount();
    }

    function wireEventListeners() {
        const selectAll = document.getElementById('ll-select-all');
        const deselectAll = document.getElementById('ll-deselect-all');
        const processBtn = document.getElementById('ll-process-selected');
        const checkboxes = document.querySelectorAll('.ll-recording-checkbox');

        if (selectAll) {
            selectAll.addEventListener('click', () => {
                checkboxes.forEach(cb => {
                    cb.checked = true;
                    state.selected.add(parseInt(cb.value));
                });
                updateSelectedCount();
            });
        }

        if (deselectAll) {
            deselectAll.addEventListener('click', () => {
                checkboxes.forEach(cb => {
                    cb.checked = false;
                    state.selected.delete(parseInt(cb.value));
                });
                updateSelectedCount();
            });
        }

        checkboxes.forEach(cb => {
            cb.addEventListener('change', (e) => {
                const id = parseInt(e.target.value);
                if (e.target.checked) {
                    state.selected.add(id);
                } else {
                    state.selected.delete(id);
                }
                updateSelectedCount();
            });
        });

        if (processBtn) {
            processBtn.addEventListener('click', processSelectedRecordings);
        }
    }

    function updateSelectedCount() {
        const countEl = document.getElementById('ll-selected-count');
        const processBtn = document.getElementById('ll-process-selected');

        if (countEl) {
            countEl.textContent = state.selected.size;
        }

        if (processBtn) {
            processBtn.disabled = state.selected.size === 0 || state.processing;
        }
    }

    async function processSelectedRecordings() {
        if (state.processing || state.selected.size === 0) return;

        state.processing = true;
        const processBtn = document.getElementById('ll-process-selected');
        const statusDiv = document.getElementById('ll-processor-status');
        const progressBar = statusDiv.querySelector('.ll-progress-fill');
        const statusText = statusDiv.querySelector('.ll-status-text');

        if (processBtn) processBtn.disabled = true;
        if (statusDiv) statusDiv.style.display = 'block';

        const selectedRecordings = state.recordings.filter(r => state.selected.has(r.id));
        let completed = 0;

        for (const recording of selectedRecordings) {
            try {
                if (statusText) {
                    statusText.textContent = `Processing: ${recording.title} (${completed + 1}/${selectedRecordings.length})`;
                }

                const processedBlob = await processAudioFile(recording.audioUrl);
                await uploadProcessedAudio(recording.id, processedBlob, recording.title);

                completed++;
                if (progressBar) {
                    progressBar.style.width = `${(completed / selectedRecordings.length) * 100}%`;
                }

                // Mark as processed in UI
                const item = document.querySelector(`.ll-recording-item[data-id="${recording.id}"]`);
                if (item) {
                    item.classList.add('ll-processed');
                    const checkbox = item.querySelector('.ll-recording-checkbox');
                    if (checkbox) checkbox.disabled = true;
                }

            } catch (error) {
                console.error(`Failed to process ${recording.title}:`, error);
                if (statusText) {
                    statusText.textContent = `Error processing ${recording.title}: ${error.message}`;
                }
                await new Promise(resolve => setTimeout(resolve, 2000));
            }
        }

        if (statusText) {
            statusText.textContent = `Completed! Processed ${completed} of ${selectedRecordings.length} recordings.`;
        }

        state.processing = false;
        state.selected.clear();
        updateSelectedCount();

        setTimeout(() => {
            if (statusDiv) statusDiv.style.display = 'none';
            if (completed === selectedRecordings.length) {
                location.reload();
            }
        }, 3000);
    }

    async function processAudioFile(url) {
        // Load audio file
        const response = await fetch(url);
        const arrayBuffer = await response.arrayBuffer();
        const audioBuffer = await state.audioContext.decodeAudioData(arrayBuffer);

        // Process: trim silence, reduce noise, normalize
        let processedBuffer = trimSilence(audioBuffer);
        processedBuffer = await reduceNoise(processedBuffer); // AWAIT this!
        processedBuffer = normalizeLoudness(processedBuffer);

        // Convert to MP3
        return await encodeToMP3(processedBuffer);
    }

    function trimSilence(audioBuffer) {
        const channelData = audioBuffer.getChannelData(0);
        const sampleRate = audioBuffer.sampleRate;
        const threshold = 0.02; // Increased from 0.01 - less sensitive
        const windowSize = Math.floor(0.01 * sampleRate); // 10ms window for smoother detection

        // Find start of audio (trim leading silence)
        let startIndex = 0;
        for (let i = 0; i < channelData.length - windowSize; i++) {
            // Check average level over a small window
            let sum = 0;
            for (let j = 0; j < windowSize; j++) {
                sum += Math.abs(channelData[i + j]);
            }
            const average = sum / windowSize;

            if (average > threshold) {
                // Back up slightly to avoid cutting into the audio
                startIndex = Math.max(0, i - Math.floor(0.05 * sampleRate)); // 50ms padding
                break;
            }
        }

        // Find end of audio (trim trailing silence)
        let endIndex = channelData.length;
        for (let i = channelData.length - windowSize; i >= 0; i--) {
            let sum = 0;
            for (let j = 0; j < windowSize; j++) {
                sum += Math.abs(channelData[i + j]);
            }
            const average = sum / windowSize;

            if (average > threshold) {
                // Add slight padding to avoid cutting off
                endIndex = Math.min(channelData.length, i + windowSize + Math.floor(0.05 * sampleRate));
                break;
            }
        }

        // Ensure we have some audio
        if (endIndex <= startIndex) {
            return audioBuffer;
        }

        // Create new buffer with trimmed audio
        const trimmedLength = endIndex - startIndex;
        const trimmedBuffer = state.audioContext.createBuffer(
            audioBuffer.numberOfChannels,
            trimmedLength,
            sampleRate
        );

        for (let channel = 0; channel < audioBuffer.numberOfChannels; channel++) {
            const sourceData = audioBuffer.getChannelData(channel);
            const targetData = trimmedBuffer.getChannelData(channel);
            for (let i = 0; i < trimmedLength; i++) {
                targetData[i] = sourceData[startIndex + i];
            }
        }

        return trimmedBuffer;
    }

    async function reduceNoise(audioBuffer) {
        // Simple noise reduction using a high-pass filter
        const offlineContext = new OfflineAudioContext(
            audioBuffer.numberOfChannels,
            audioBuffer.length,
            audioBuffer.sampleRate
        );

        const source = offlineContext.createBufferSource();
        source.buffer = audioBuffer;

        // High-pass filter to remove low-frequency noise
        const highpass = offlineContext.createBiquadFilter();
        highpass.type = 'highpass';
        highpass.frequency.value = 80; // Remove frequencies below 80Hz

        // Low-pass filter to remove high-frequency noise
        const lowpass = offlineContext.createBiquadFilter();
        lowpass.type = 'lowpass';
        lowpass.frequency.value = 8000; // Remove frequencies above 8kHz

        source.connect(highpass);
        highpass.connect(lowpass);
        lowpass.connect(offlineContext.destination);

        source.start();

        return await offlineContext.startRendering();
    }

    function normalizeLoudness(audioBuffer) {
        // Find peak amplitude across all channels
        let peak = 0;
        for (let channel = 0; channel < audioBuffer.numberOfChannels; channel++) {
            const channelData = audioBuffer.getChannelData(channel);
            for (let i = 0; i < channelData.length; i++) {
                peak = Math.max(peak, Math.abs(channelData[i]));
            }
        }

        if (peak === 0) return audioBuffer;

        // Target peak at -3dB (0.707) to avoid clipping
        const targetPeak = 0.707;
        const gain = targetPeak / peak;

        // Create new buffer with normalized audio
        const normalizedBuffer = state.audioContext.createBuffer(
            audioBuffer.numberOfChannels,
            audioBuffer.length,
            audioBuffer.sampleRate
        );

        for (let channel = 0; channel < audioBuffer.numberOfChannels; channel++) {
            const sourceData = audioBuffer.getChannelData(channel);
            const targetData = normalizedBuffer.getChannelData(channel);
            for (let i = 0; i < sourceData.length; i++) {
                targetData[i] = sourceData[i] * gain;
            }
        }

        return normalizedBuffer;
    }

    async function encodeToMP3(audioBuffer) {
        // Load lamejs library dynamically if not already loaded
        if (!window.lamejs) {
            await loadLameJS();
        }

        const channels = audioBuffer.numberOfChannels;
        const sampleRate = audioBuffer.sampleRate;
        const samples = audioBuffer.length;

        // Convert float samples to 16-bit PCM
        const leftChannel = audioBuffer.getChannelData(0);
        const rightChannel = channels > 1 ? audioBuffer.getChannelData(1) : leftChannel;

        const left = new Int16Array(samples);
        const right = new Int16Array(samples);

        for (let i = 0; i < samples; i++) {
            left[i] = Math.max(-32768, Math.min(32767, leftChannel[i] * 32767));
            right[i] = Math.max(-32768, Math.min(32767, rightChannel[i] * 32767));
        }

        // Encode to MP3
        const mp3encoder = new lamejs.Mp3Encoder(channels, sampleRate, 128); // 128 kbps
        const mp3Data = [];
        const sampleBlockSize = 1152;

        for (let i = 0; i < samples; i += sampleBlockSize) {
            const leftChunk = left.subarray(i, i + sampleBlockSize);
            const rightChunk = right.subarray(i, i + sampleBlockSize);
            const mp3buf = mp3encoder.encodeBuffer(leftChunk, rightChunk);
            if (mp3buf.length > 0) {
                mp3Data.push(mp3buf);
            }
        }

        const mp3buf = mp3encoder.flush();
        if (mp3buf.length > 0) {
            mp3Data.push(mp3buf);
        }

        return new Blob(mp3Data, { type: 'audio/mp3' });
    }

    function loadLameJS() {
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/lamejs@1.2.1/lame.min.js';
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    async function uploadProcessedAudio(postId, audioBlob, title) {
        const formData = new FormData();
        formData.append('action', 'll_save_processed_audio');
        formData.append('nonce', window.llAudioProcessor.nonce);
        formData.append('post_id', postId);
        formData.append('audio', audioBlob, `${title}.mp3`);

        const response = await fetch(window.llAudioProcessor.ajaxUrl, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        if (!result.success) {
            throw new Error(result.data || 'Failed to save processed audio');
        }

        return result;
    }

})();