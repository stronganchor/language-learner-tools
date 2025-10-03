(function () {
    'use strict';

    const state = {
        recordings: [],
        selected: new Set(),
        processing: false,
        audioContext: null,
        reviewData: new Map(), // Map of postId -> { buffer, trimStart, trimEnd, options }
        globalOptions: {
            enableTrim: true,
            enableNoise: true,
            enableLoudness: true
        }
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

        // Global options
        const enableTrim = document.getElementById('ll-enable-trim');
        const enableNoise = document.getElementById('ll-enable-noise');
        const enableLoudness = document.getElementById('ll-enable-loudness');

        if (enableTrim) {
            enableTrim.addEventListener('change', (e) => {
                state.globalOptions.enableTrim = e.target.checked;
            });
        }

        if (enableNoise) {
            enableNoise.addEventListener('change', (e) => {
                state.globalOptions.enableNoise = e.target.checked;
            });
        }

        if (enableLoudness) {
            enableLoudness.addEventListener('change', (e) => {
                state.globalOptions.enableLoudness = e.target.checked;
            });
        }

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

        // Review interface buttons
        document.addEventListener('click', (e) => {
            if (e.target.id === 'll-save-all') {
                saveAllProcessedAudio();
            } else if (e.target.id === 'll-cancel-review') {
                cancelReview();
            } else if (e.target.classList.contains('ll-reprocess-btn')) {
                const postId = parseInt(e.target.dataset.postId);
                reprocessSingleFile(postId);
            }
        });
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

                const processedData = await processAudioFile(recording.audioUrl, state.globalOptions);

                // Store in review data
                state.reviewData.set(recording.id, {
                    recording: recording,
                    originalBuffer: processedData.originalBuffer,
                    processedBuffer: processedData.processedBuffer,
                    trimStart: processedData.trimStart,
                    trimEnd: processedData.trimEnd,
                    options: { ...state.globalOptions }
                });

                completed++;
                if (progressBar) {
                    progressBar.style.width = `${(completed / selectedRecordings.length) * 100}%`;
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
            statusText.textContent = `Processing complete! Review the results below.`;
        }

        state.processing = false;

        setTimeout(() => {
            if (statusDiv) statusDiv.style.display = 'none';
            showReviewInterface();
        }, 1500);
    }

    async function processAudioFile(url, options) {
        // Load audio file
        const response = await fetch(url);
        const arrayBuffer = await response.arrayBuffer();
        const originalBuffer = await state.audioContext.decodeAudioData(arrayBuffer.slice(0));

        let processedBuffer = originalBuffer;
        let trimStart = 0;
        let trimEnd = originalBuffer.length;

        // Trim silence if enabled
        if (options.enableTrim) {
            const trimResult = detectSilenceBoundaries(originalBuffer);
            trimStart = trimResult.start;
            trimEnd = trimResult.end;
            processedBuffer = trimSilence(originalBuffer, trimStart, trimEnd);
        }

        // Reduce noise if enabled
        if (options.enableNoise) {
            processedBuffer = await reduceNoise(processedBuffer);
        }

        // Normalize loudness if enabled
        if (options.enableLoudness) {
            processedBuffer = normalizeLoudness(processedBuffer);
        }

        return {
            originalBuffer,
            processedBuffer,
            trimStart,
            trimEnd
        };
    }

    function detectSilenceBoundaries(audioBuffer) {
        const channelData = audioBuffer.getChannelData(0);
        const sampleRate = audioBuffer.sampleRate;
        const threshold = 0.02;
        const windowSize = Math.floor(0.01 * sampleRate);

        let startIndex = 0;
        for (let i = 0; i < channelData.length - windowSize; i++) {
            let sum = 0;
            for (let j = 0; j < windowSize; j++) {
                sum += Math.abs(channelData[i + j]);
            }
            const average = sum / windowSize;

            if (average > threshold) {
                startIndex = Math.max(0, i - Math.floor(0.1 * sampleRate));
                break;
            }
        }

        let endIndex = channelData.length;
        for (let i = channelData.length - windowSize; i >= 0; i--) {
            let sum = 0;
            for (let j = 0; j < windowSize; j++) {
                sum += Math.abs(channelData[i + j]);
            }
            const average = sum / windowSize;

            if (average > threshold) {
                endIndex = Math.min(channelData.length, i + windowSize + Math.floor(0.3 * sampleRate));
                break;
            }
        }

        return { start: startIndex, end: endIndex };
    }

    function trimSilence(audioBuffer, startIndex, endIndex) {
        if (endIndex <= startIndex) {
            return audioBuffer;
        }

        const trimmedLength = endIndex - startIndex;
        const trimmedBuffer = state.audioContext.createBuffer(
            audioBuffer.numberOfChannels,
            trimmedLength,
            audioBuffer.sampleRate
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
        const offlineContext = new OfflineAudioContext(
            audioBuffer.numberOfChannels,
            audioBuffer.length,
            audioBuffer.sampleRate
        );

        const source = offlineContext.createBufferSource();
        source.buffer = audioBuffer;

        const highpass = offlineContext.createBiquadFilter();
        highpass.type = 'highpass';
        highpass.frequency.value = 80;

        const lowpass = offlineContext.createBiquadFilter();
        lowpass.type = 'lowpass';
        lowpass.frequency.value = 8000;

        source.connect(highpass);
        highpass.connect(lowpass);
        lowpass.connect(offlineContext.destination);

        source.start();

        return await offlineContext.startRendering();
    }

    function normalizeLoudness(audioBuffer) {
        let peak = 0;
        for (let channel = 0; channel < audioBuffer.numberOfChannels; channel++) {
            const channelData = audioBuffer.getChannelData(channel);
            for (let i = 0; i < channelData.length; i++) {
                peak = Math.max(peak, Math.abs(channelData[i]));
            }
        }

        if (peak === 0) return audioBuffer;

        const targetPeak = 0.707;
        const gain = targetPeak / peak;

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

    function showReviewInterface() {
        const reviewInterface = document.getElementById('ll-review-interface');
        const container = document.getElementById('ll-review-files-container');

        if (!reviewInterface || !container) return;

        container.innerHTML = '';

        state.reviewData.forEach((data, postId) => {
            const fileDiv = createReviewFileElement(postId, data);
            container.appendChild(fileDiv);
        });

        reviewInterface.style.display = 'block';
        reviewInterface.scrollIntoView({ behavior: 'smooth' });
    }

    function createReviewFileElement(postId, data) {
        const div = document.createElement('div');
        div.className = 'll-review-file';
        div.dataset.postId = postId;

        const { recording, originalBuffer, processedBuffer, trimStart, trimEnd, options } = data;

        div.innerHTML = `
            <div class="ll-review-header">
                <h3 class="ll-review-title">${escapeHtml(recording.title)}</h3>
                <div class="ll-file-options">
                    <label>
                        <input type="checkbox" class="ll-file-trim" ${options.enableTrim ? 'checked' : ''}>
                        Trim
                    </label>
                    <label>
                        <input type="checkbox" class="ll-file-noise" ${options.enableNoise ? 'checked' : ''}>
                        Noise Reduction
                    </label>
                    <label>
                        <input type="checkbox" class="ll-file-loudness" ${options.enableLoudness ? 'checked' : ''}>
                        Loudness
                    </label>
                </div>
            </div>
            <div class="ll-waveform-container" data-post-id="${postId}">
                <canvas class="ll-waveform-canvas"></canvas>
            </div>
            <div class="ll-playback-controls">
                <audio controls preload="auto"></audio>
                <button class="button ll-reprocess-btn" data-post-id="${postId}">Reprocess</button>
            </div>
        `;

        // Render waveform
        setTimeout(() => {
            renderWaveform(div, originalBuffer, trimStart, trimEnd);
            setupAudioPlayback(div, processedBuffer);
            setupBoundaryDragging(div, postId, originalBuffer);
        }, 0);

        return div;
    }

    function renderWaveform(container, audioBuffer, trimStart, trimEnd) {
        const canvas = container.querySelector('.ll-waveform-canvas');
        const waveformContainer = container.querySelector('.ll-waveform-container');

        if (!canvas || !waveformContainer) return;

        const dpr = window.devicePixelRatio || 1;
        const rect = waveformContainer.getBoundingClientRect();

        canvas.width = rect.width * dpr;
        canvas.height = rect.height * dpr;
        canvas.style.width = rect.width + 'px';
        canvas.style.height = rect.height + 'px';

        const ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);

        // Draw waveform
        const channelData = audioBuffer.getChannelData(0);
        const samplesPerPixel = Math.floor(channelData.length / rect.width);
        const centerY = rect.height / 2;

        ctx.fillStyle = '#2ecc71';
        ctx.strokeStyle = '#27ae60';
        ctx.lineWidth = 1;

        for (let x = 0; x < rect.width; x++) {
            const start = x * samplesPerPixel;
            const end = start + samplesPerPixel;

            let min = 1;
            let max = -1;

            for (let i = start; i < end && i < channelData.length; i++) {
                const sample = channelData[i];
                if (sample < min) min = sample;
                if (sample > max) max = sample;
            }

            const yTop = centerY - (max * centerY);
            const yBottom = centerY - (min * centerY);
            const height = yBottom - yTop;

            ctx.fillRect(x, yTop, 1, height);
        }

        // Add trim boundaries
        addTrimBoundaries(waveformContainer, trimStart, trimEnd, audioBuffer.length);
    }

    function addTrimBoundaries(container, trimStart, trimEnd, totalSamples) {
        const startPercent = (trimStart / totalSamples) * 100;
        const endPercent = (trimEnd / totalSamples) * 100;

        // Remove existing boundaries
        container.querySelectorAll('.ll-trim-boundary, .ll-trimmed-region').forEach(el => el.remove());

        // Start boundary
        const startBoundary = document.createElement('div');
        startBoundary.className = 'll-trim-boundary ll-start';
        startBoundary.style.left = startPercent + '%';
        startBoundary.dataset.position = trimStart;
        container.appendChild(startBoundary);

        // End boundary
        const endBoundary = document.createElement('div');
        endBoundary.className = 'll-trim-boundary ll-end';
        endBoundary.style.left = endPercent + '%';
        endBoundary.dataset.position = trimEnd;
        container.appendChild(endBoundary);

        // Trimmed regions
        if (trimStart > 0) {
            const startRegion = document.createElement('div');
            startRegion.className = 'll-trimmed-region ll-start';
            startRegion.style.width = startPercent + '%';
            container.appendChild(startRegion);
        }

        if (trimEnd < totalSamples) {
            const endRegion = document.createElement('div');
            endRegion.className = 'll-trimmed-region ll-end';
            endRegion.style.left = endPercent + '%';
            endRegion.style.width = (100 - endPercent) + '%';
            container.appendChild(endRegion);
        }
    }

    function setupAudioPlayback(container, audioBuffer) {
        const audio = container.querySelector('audio');
        if (!audio) return;

        // Convert buffer to blob and set as audio source
        const blob = audioBufferToWav(audioBuffer);
        const url = URL.createObjectURL(blob);
        audio.src = url;
    }

    function setupBoundaryDragging(container, postId, audioBuffer) {
        const waveformContainer = container.querySelector('.ll-waveform-container');
        if (!waveformContainer) return;

        const startBoundary = waveformContainer.querySelector('.ll-trim-boundary.ll-start');
        const endBoundary = waveformContainer.querySelector('.ll-trim-boundary.ll-end');

        if (!startBoundary || !endBoundary) return;

        let isDragging = false;
        let currentBoundary = null;
        let containerRect = null;

        const startDrag = (e, boundary) => {
            isDragging = true;
            currentBoundary = boundary;
            containerRect = waveformContainer.getBoundingClientRect();

            waveformContainer.style.cursor = 'ew-resize';
            e.preventDefault();
        };

        const onDrag = (e) => {
            if (!isDragging || !currentBoundary || !containerRect) return;

            const clientX = e.type.includes('touch') ? e.touches[0].clientX : e.clientX;
            const relativeX = clientX - containerRect.left;
            const percent = Math.max(0, Math.min(100, (relativeX / containerRect.width) * 100));

            const isStart = currentBoundary.classList.contains('ll-start');
            const otherBoundary = isStart ? endBoundary : startBoundary;
            const otherPercent = parseFloat(otherBoundary.style.left);

            // Prevent boundaries from crossing
            let constrainedPercent = percent;
            if (isStart && percent >= otherPercent - 1) {
                constrainedPercent = otherPercent - 1;
            } else if (!isStart && percent <= otherPercent + 1) {
                constrainedPercent = otherPercent + 1;
            }

            currentBoundary.style.left = constrainedPercent + '%';

            // Update sample position
            const samplePosition = Math.floor((constrainedPercent / 100) * audioBuffer.length);
            currentBoundary.dataset.position = samplePosition;

            // Update trimmed regions
            updateTrimmedRegions(waveformContainer, startBoundary, endBoundary);

            e.preventDefault();
        };

        const endDrag = () => {
            if (!isDragging) return;

            isDragging = false;
            waveformContainer.style.cursor = '';

            // Get new trim positions
            const newTrimStart = parseInt(startBoundary.dataset.position);
            const newTrimEnd = parseInt(endBoundary.dataset.position);

            // Update review data
            const data = state.reviewData.get(postId);
            if (data) {
                data.trimStart = newTrimStart;
                data.trimEnd = newTrimEnd;
                data.manualBoundaries = true; // Mark as manually adjusted

                // Reprocess audio with new boundaries
                updateProcessedAudio(postId, data);
            }

            currentBoundary = null;
            containerRect = null;
        };

        // Mouse events
        startBoundary.addEventListener('mousedown', (e) => startDrag(e, startBoundary));
        endBoundary.addEventListener('mousedown', (e) => startDrag(e, endBoundary));

        document.addEventListener('mousemove', onDrag);
        document.addEventListener('mouseup', endDrag);

        // Touch events
        startBoundary.addEventListener('touchstart', (e) => startDrag(e, startBoundary));
        endBoundary.addEventListener('touchstart', (e) => startDrag(e, endBoundary));

        document.addEventListener('touchmove', onDrag, { passive: false });
        document.addEventListener('touchend', endDrag);

        // Cleanup on container removal
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.removedNodes.forEach((node) => {
                    if (node === container) {
                        document.removeEventListener('mousemove', onDrag);
                        document.removeEventListener('mouseup', endDrag);
                        document.removeEventListener('touchmove', onDrag);
                        document.removeEventListener('touchend', endDrag);
                        observer.disconnect();
                    }
                });
            });
        });

        observer.observe(container.parentNode, { childList: true });
    }

    function updateTrimmedRegions(container, startBoundary, endBoundary) {
        const startPercent = parseFloat(startBoundary.style.left);
        const endPercent = parseFloat(endBoundary.style.left);

        // Remove old regions
        container.querySelectorAll('.ll-trimmed-region').forEach(el => el.remove());

        // Add updated regions
        if (startPercent > 0) {
            const startRegion = document.createElement('div');
            startRegion.className = 'll-trimmed-region ll-start';
            startRegion.style.width = startPercent + '%';
            container.appendChild(startRegion);
        }

        if (endPercent < 100) {
            const endRegion = document.createElement('div');
            endRegion.className = 'll-trimmed-region ll-end';
            endRegion.style.left = endPercent + '%';
            endRegion.style.width = (100 - endPercent) + '%';
            container.appendChild(endRegion);
        }
    }

    async function updateProcessedAudio(postId, data) {
        const container = document.querySelector(`.ll-review-file[data-post-id="${postId}"]`);
        if (!container) return;

        const audioElement = container.querySelector('audio');
        if (!audioElement) return;

        // Show processing indicator
        audioElement.style.opacity = '0.5';

        try {
            // Get current options
            const enableTrim = container.querySelector('.ll-file-trim').checked;
            const enableNoise = container.querySelector('.ll-file-noise').checked;
            const enableLoudness = container.querySelector('.ll-file-loudness').checked;

            let processedBuffer = data.originalBuffer;

            // Apply trim with current boundaries (preserve manual adjustments)
            if (enableTrim) {
                processedBuffer = trimSilence(data.originalBuffer, data.trimStart, data.trimEnd);
            }

            // Apply noise reduction
            if (enableNoise) {
                processedBuffer = await reduceNoise(processedBuffer);
            }

            // Apply loudness normalization
            if (enableLoudness) {
                processedBuffer = normalizeLoudness(processedBuffer);
            }

            // Update stored data (preserve manualBoundaries flag)
            data.processedBuffer = processedBuffer;
            data.options = { enableTrim, enableNoise, enableLoudness };

            // Update audio playback
            setupAudioPlayback(container, processedBuffer);

        } catch (error) {
            console.error('Error updating processed audio:', error);
        } finally {
            audioElement.style.opacity = '1';
        }
    }

    async function reprocessSingleFile(postId) {
        const data = state.reviewData.get(postId);
        if (!data) return;

        const container = document.querySelector(`.ll-review-file[data-post-id="${postId}"]`);
        if (!container) return;

        // Get current options
        const enableTrim = container.querySelector('.ll-file-trim').checked;
        const enableNoise = container.querySelector('.ll-file-noise').checked;
        const enableLoudness = container.querySelector('.ll-file-loudness').checked;

        // Show processing indicator
        const reprocessBtn = container.querySelector('.ll-reprocess-btn');
        const originalText = reprocessBtn.textContent;
        reprocessBtn.textContent = 'Processing...';
        reprocessBtn.disabled = true;

        try {
            // Use existing trim boundaries (user may have adjusted them)
            let trimStart = data.trimStart;
            let trimEnd = data.trimEnd;

            // Only re-detect boundaries if trim option changed from off to on
            // and we don't have manual boundaries set
            if (enableTrim && !data.manualBoundaries && (!data.options || !data.options.enableTrim)) {
                const detected = detectSilenceBoundaries(data.originalBuffer);
                trimStart = detected.start;
                trimEnd = detected.end;
            }

            let processedBuffer = data.originalBuffer;

            // Apply trim with current boundaries
            if (enableTrim) {
                processedBuffer = trimSilence(data.originalBuffer, trimStart, trimEnd);
            }

            // Apply noise reduction
            if (enableNoise) {
                processedBuffer = await reduceNoise(processedBuffer);
            }

            // Apply loudness normalization
            if (enableLoudness) {
                processedBuffer = normalizeLoudness(processedBuffer);
            }

            // Update review data
            data.processedBuffer = processedBuffer;
            data.trimStart = trimStart;
            data.trimEnd = trimEnd;
            data.options = { enableTrim, enableNoise, enableLoudness };

            // Re-render waveform and audio
            const waveformContainer = container.querySelector('.ll-waveform-container');
            if (waveformContainer) {
                // Clear old boundaries
                waveformContainer.querySelectorAll('.ll-trim-boundary, .ll-trimmed-region').forEach(el => el.remove());

                // Re-render
                renderWaveform(container, data.originalBuffer, data.trimStart, data.trimEnd);
                setupAudioPlayback(container, data.processedBuffer);
                setupBoundaryDragging(container, postId, data.originalBuffer);
            }

        } catch (error) {
            console.error('Error reprocessing file:', error);
            alert('Error reprocessing file: ' + error.message);
        } finally {
            reprocessBtn.textContent = originalText;
            reprocessBtn.disabled = false;
        }
    }

    function audioBufferToWav(audioBuffer) {
        const numChannels = audioBuffer.numberOfChannels;
        const sampleRate = audioBuffer.sampleRate;
        const format = 1; // PCM
        const bitDepth = 16;

        const bytesPerSample = bitDepth / 8;
        const blockAlign = numChannels * bytesPerSample;

        const data = [];
        for (let i = 0; i < audioBuffer.length; i++) {
            for (let channel = 0; channel < numChannels; channel++) {
                const sample = Math.max(-1, Math.min(1, audioBuffer.getChannelData(channel)[i]));
                data.push(sample < 0 ? sample * 0x8000 : sample * 0x7FFF);
            }
        }

        const dataLength = data.length * bytesPerSample;
        const buffer = new ArrayBuffer(44 + dataLength);
        const view = new DataView(buffer);

        const writeString = (offset, string) => {
            for (let i = 0; i < string.length; i++) {
                view.setUint8(offset + i, string.charCodeAt(i));
            }
        };

        writeString(0, 'RIFF');
        view.setUint32(4, 36 + dataLength, true);
        writeString(8, 'WAVE');
        writeString(12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, format, true);
        view.setUint16(22, numChannels, true);
        view.setUint32(24, sampleRate, true);
        view.setUint32(28, sampleRate * blockAlign, true);
        view.setUint16(32, blockAlign, true);
        view.setUint16(34, bitDepth, true);
        writeString(36, 'data');
        view.setUint32(40, dataLength, true);

        let offset = 44;
        for (let i = 0; i < data.length; i++) {
            view.setInt16(offset, data[i], true);
            offset += 2;
        }

        return new Blob([buffer], { type: 'audio/wav' });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function wireEventListeners() {
        const selectAll = document.getElementById('ll-select-all');
        const deselectAll = document.getElementById('ll-deselect-all');
        const processBtn = document.getElementById('ll-process-selected');
        const checkboxes = document.querySelectorAll('.ll-recording-checkbox');

        // Global options
        const enableTrim = document.getElementById('ll-enable-trim');
        const enableNoise = document.getElementById('ll-enable-noise');
        const enableLoudness = document.getElementById('ll-enable-loudness');

        if (enableTrim) {
            enableTrim.addEventListener('change', (e) => {
                state.globalOptions.enableTrim = e.target.checked;
            });
        }

        if (enableNoise) {
            enableNoise.addEventListener('change', (e) => {
                state.globalOptions.enableNoise = e.target.checked;
            });
        }

        if (enableLoudness) {
            enableLoudness.addEventListener('change', (e) => {
                state.globalOptions.enableLoudness = e.target.checked;
            });
        }

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

        // Review interface buttons
        document.addEventListener('click', (e) => {
            if (e.target.id === 'll-save-all') {
                saveAllProcessedAudio();
            } else if (e.target.id === 'll-cancel-review') {
                cancelReview();
            } else if (e.target.classList.contains('ll-reprocess-btn')) {
                const postId = parseInt(e.target.dataset.postId);
                reprocessSingleFile(postId);
            }
        });

        // Per-file option changes
        document.addEventListener('change', (e) => {
            if (e.target.classList.contains('ll-file-trim') ||
                e.target.classList.contains('ll-file-noise') ||
                e.target.classList.contains('ll-file-loudness')) {

                const container = e.target.closest('.ll-review-file');
                if (container) {
                    const postId = parseInt(container.dataset.postId);
                    const data = state.reviewData.get(postId);
                    if (data) {
                        updateProcessedAudio(postId, data);
                    }
                }
            }
        });
    }

    async function saveAllProcessedAudio() {
        if (state.reviewData.size === 0) {
            alert('No files to save.');
            return;
        }

        const saveBtn = document.getElementById('ll-save-all');
        const cancelBtn = document.getElementById('ll-cancel-review');

        if (!confirm(`Save ${state.reviewData.size} processed audio file(s)?`)) {
            return;
        }

        saveBtn.disabled = true;
        cancelBtn.disabled = true;
        saveBtn.textContent = 'Saving...';

        const statusDiv = document.getElementById('ll-processor-status');
        const progressBar = statusDiv.querySelector('.ll-progress-fill');
        const statusText = statusDiv.querySelector('.ll-status-text');

        if (statusDiv) statusDiv.style.display = 'block';
        if (progressBar) progressBar.style.width = '0%';

        let saved = 0;
        let failed = 0;
        const total = state.reviewData.size;

        for (const [postId, data] of state.reviewData) {
            try {
                if (statusText) {
                    statusText.textContent = `Saving: ${data.recording.title} (${saved + failed + 1}/${total})`;
                }

                await saveProcessedAudio(postId, data);
                saved++;

                // Mark the recording item as processed
                const item = document.querySelector(`.ll-recording-item[data-id="${postId}"]`);
                if (item) {
                    item.classList.add('ll-processed');
                    const checkbox = item.querySelector('.ll-recording-checkbox');
                    if (checkbox) checkbox.disabled = true;
                }

            } catch (error) {
                console.error(`Failed to save ${data.recording.title}:`, error);
                failed++;
            }

            if (progressBar) {
                progressBar.style.width = `${((saved + failed) / total) * 100}%`;
            }
        }

        if (statusText) {
            if (failed === 0) {
                statusText.textContent = `Success! Saved ${saved} of ${total} files.`;
                statusText.style.color = '#00a32a';
            } else {
                statusText.textContent = `Completed with errors: ${saved} saved, ${failed} failed.`;
                statusText.style.color = '#d63638';
            }
        }

        setTimeout(() => {
            if (failed === 0) {
                location.reload();
            } else {
                saveBtn.disabled = false;
                cancelBtn.disabled = false;
                saveBtn.textContent = 'Save All Changes';
                if (statusDiv) statusDiv.style.display = 'none';
            }
        }, 2000);
    }

    async function saveProcessedAudio(postId, data) {
        const { recording, processedBuffer } = data;

        // Convert to MP3 if lamejs is available, otherwise WAV
        let audioBlob;
        let filename;

        if (window.lamejs) {
            audioBlob = await encodeToMP3(processedBuffer);
            filename = `${sanitizeFilename(recording.title)}_processed.mp3`;
        } else {
            audioBlob = audioBufferToWav(processedBuffer);
            filename = `${sanitizeFilename(recording.title)}_processed.wav`;
        }

        const formData = new FormData();
        formData.append('action', 'll_save_processed_audio');
        formData.append('nonce', window.llAudioProcessor.nonce);
        formData.append('post_id', postId);
        formData.append('audio', audioBlob, filename);

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

    async function encodeToMP3(audioBuffer) {
        // Load lamejs if not already loaded
        if (!window.lamejs) {
            await loadLameJS();
        }

        const channels = audioBuffer.numberOfChannels;
        const sampleRate = audioBuffer.sampleRate;
        const samples = audioBuffer.length;

        const left = new Int16Array(samples);
        const right = new Int16Array(samples);

        const leftChannel = audioBuffer.getChannelData(0);
        const rightChannel = channels > 1 ? audioBuffer.getChannelData(1) : leftChannel;

        for (let i = 0; i < samples; i++) {
            left[i] = Math.max(-32768, Math.min(32767, leftChannel[i] * 32767));
            right[i] = Math.max(-32768, Math.min(32767, rightChannel[i] * 32767));
        }

        const mp3encoder = new lamejs.Mp3Encoder(channels, sampleRate, 128);
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
            if (window.lamejs) {
                resolve();
                return;
            }

            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/lamejs@1.2.1/lame.min.js';
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    function sanitizeFilename(filename) {
        return filename
            .replace(/[^a-z0-9_\-]/gi, '_')
            .replace(/_{2,}/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    function cancelReview() {
        if (confirm('Are you sure you want to cancel? All processing will be lost.')) {
            location.reload();
        }
    }

})();