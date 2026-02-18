(function () {
    'use strict';

    const state = {
        recordings: [],
        selected: new Set(),
        processing: false,
        deleting: false,
        saving: false,
        audioContext: null,
        reviewData: new Map(),
        // Track last clicked checkbox index for shift-click range selection
        lastSelectedIndexByTab: {
            queue: null,
            duplicates: null
        },
        activeTab: 'queue',
        globalOptions: {
            enableTrim: true,
            enableNoise: true,
            enableLoudness: true
        },
        recordingTypes: [],
        recordingTypeIcons: {},
        titleSavingWordIds: new Set()
    };

    const TARGET_LUFS = -18.0;
    const i18n = (window.llAudioProcessor && window.llAudioProcessor.i18n && typeof window.llAudioProcessor.i18n === 'object')
        ? window.llAudioProcessor.i18n
        : {};

    document.addEventListener('DOMContentLoaded', init);

    function t(key, fallback) {
        if (Object.prototype.hasOwnProperty.call(i18n, key) && typeof i18n[key] === 'string' && i18n[key] !== '') {
            return i18n[key];
        }
        return fallback;
    }

    function formatText(template, values = []) {
        let output = String(template || '');

        output = output.replace(/%(\d+)\$[sd]/g, (match, index) => {
            const mappedIndex = parseInt(index, 10) - 1;
            if (!Number.isInteger(mappedIndex) || mappedIndex < 0 || typeof values[mappedIndex] === 'undefined') {
                return '';
            }
            return String(values[mappedIndex]);
        });

        let nextIndex = 0;
        output = output.replace(/%[sd]/g, () => {
            if (typeof values[nextIndex] === 'undefined') {
                nextIndex += 1;
                return '';
            }
            const value = values[nextIndex];
            nextIndex += 1;
            return String(value);
        });

        return output;
    }

    function getSaveOverlayElements() {
        return {
            overlay: document.getElementById('ll-save-progress-overlay'),
            fill: document.getElementById('ll-save-progress-fill'),
            current: document.getElementById('ll-save-progress-current'),
            count: document.getElementById('ll-save-progress-count')
        };
    }

    function setSaveOverlayState(message, completed, total, tone = '') {
        const elements = getSaveOverlayElements();
        if (!elements.overlay) {
            return;
        }

        elements.overlay.classList.remove('is-success', 'is-error');
        if (tone === 'success') {
            elements.overlay.classList.add('is-success');
        } else if (tone === 'error') {
            elements.overlay.classList.add('is-error');
        }

        if (elements.current) {
            elements.current.textContent = message;
        }

        if (elements.fill) {
            const progress = total > 0 ? Math.max(0, Math.min(100, (completed / total) * 100)) : 0;
            elements.fill.style.width = `${progress}%`;
        }

        if (elements.count) {
            const countTemplate = t('saveCountTemplate', '%1$d / %2$d complete');
            elements.count.textContent = formatText(countTemplate, [completed, total]);
        }
    }

    function showSaveOverlay(total) {
        const elements = getSaveOverlayElements();
        if (!elements.overlay) {
            return;
        }
        elements.overlay.hidden = false;
        elements.overlay.setAttribute('aria-hidden', 'false');
        setSaveOverlayState(t('savePreparing', 'Preparing uploads...'), 0, total);
    }

    function hideSaveOverlay() {
        const elements = getSaveOverlayElements();
        if (!elements.overlay) {
            return;
        }
        elements.overlay.hidden = true;
        elements.overlay.setAttribute('aria-hidden', 'true');
        elements.overlay.classList.remove('is-success', 'is-error');
    }

    function handleBeforeUnload(event) {
        if (!state.saving) {
            return undefined;
        }
        const warning = t('beforeUnloadWarning', 'Saving is still in progress. Leaving this page will interrupt uploads.');
        event.preventDefault();
        event.returnValue = warning;
        return warning;
    }

    function setSavingState(isSaving) {
        state.saving = !!isSaving;

        document.body.classList.toggle('ll-audio-save-in-progress', state.saving);

        if (state.saving) {
            document.querySelectorAll('.ll-audio-processor-wrap audio').forEach(audio => {
                try {
                    audio.pause();
                } catch (error) {
                    // no-op
                }
            });
            window.addEventListener('beforeunload', handleBeforeUnload);
        } else {
            window.removeEventListener('beforeunload', handleBeforeUnload);
        }
    }

    function getWordTitleBlocks(parentWordId) {
        if (!Number.isInteger(parentWordId) || parentWordId <= 0) {
            return [];
        }
        return Array.from(document.querySelectorAll(`.ll-word-title-block[data-parent-word-id="${parentWordId}"]`));
    }

    function resetWordTitleEditor(block) {
        if (!block) {
            return;
        }
        const titleEl = block.querySelector('.ll-recording-title-text');
        const inputEl = block.querySelector('.ll-word-title-input');
        if (titleEl && inputEl) {
            inputEl.value = titleEl.textContent.trim();
        }
    }

    function openWordTitleEditor(block) {
        if (!block || state.saving) {
            return;
        }
        const editor = block.querySelector('.ll-word-title-editor');
        if (!editor) {
            return;
        }
        resetWordTitleEditor(block);
        block.classList.add('is-editing');
        editor.hidden = false;
        const inputEl = block.querySelector('.ll-word-title-input');
        if (inputEl) {
            inputEl.focus();
            inputEl.select();
        }
    }

    function closeWordTitleEditor(block) {
        if (!block) {
            return;
        }
        const editor = block.querySelector('.ll-word-title-editor');
        if (!editor) {
            return;
        }
        resetWordTitleEditor(block);
        editor.hidden = true;
        block.classList.remove('is-editing');
    }

    function setWordTitleControlsDisabled(parentWordId, disabled) {
        const blocks = getWordTitleBlocks(parentWordId);
        blocks.forEach(block => {
            const input = block.querySelector('.ll-word-title-input');
            const saveBtn = block.querySelector('.ll-save-word-title-btn');
            const cancelBtn = block.querySelector('.ll-cancel-word-title-btn');
            const editBtn = block.querySelector('.ll-edit-word-title-btn');

            if (input) {
                input.disabled = !!disabled;
            }
            if (cancelBtn) {
                cancelBtn.disabled = !!disabled;
            }
            if (editBtn) {
                editBtn.disabled = !!disabled;
            }
            if (saveBtn) {
                if (disabled) {
                    if (!saveBtn.dataset.originalText) {
                        saveBtn.dataset.originalText = saveBtn.textContent;
                    }
                    saveBtn.textContent = t('titleSaving', 'Saving...');
                } else if (saveBtn.dataset.originalText) {
                    saveBtn.textContent = saveBtn.dataset.originalText;
                }
                saveBtn.disabled = !!disabled;
            }
        });
    }

    function applyWordTitleToDom(parentWordId, nextTitle) {
        if (!Number.isInteger(parentWordId) || parentWordId <= 0) {
            return;
        }
        const safeTitle = String(nextTitle || '').trim();
        if (!safeTitle) {
            return;
        }

        document
            .querySelectorAll(`.ll-recording-item[data-parent-word-id="${parentWordId}"] .ll-recording-title-text`)
            .forEach(el => {
                el.textContent = safeTitle;
            });

        document
            .querySelectorAll(`.ll-review-file[data-parent-word-id="${parentWordId}"] .ll-recording-title-text`)
            .forEach(el => {
                el.textContent = safeTitle;
            });

        document
            .querySelectorAll(`.ll-word-title-block[data-parent-word-id="${parentWordId}"] .ll-word-title-input`)
            .forEach(el => {
                el.value = safeTitle;
            });
    }

    function syncWordTitleAcrossState(parentWordId, nextTitle) {
        if (!Number.isInteger(parentWordId) || parentWordId <= 0) {
            return;
        }
        const safeTitle = String(nextTitle || '').trim();
        if (!safeTitle) {
            return;
        }

        state.recordings.forEach(recording => {
            if (parseInt(recording.parentWordId, 10) === parentWordId) {
                recording.title = safeTitle;
            }
        });

        state.reviewData.forEach(data => {
            if (data && data.recording && parseInt(data.recording.parentWordId, 10) === parentWordId) {
                data.recording.title = safeTitle;
            }
        });
    }

    async function saveWordTitle(parentWordId, title) {
        const response = await fetch(window.llAudioProcessor.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'll_audio_processor_update_word_title',
                nonce: window.llAudioProcessor.nonce,
                word_id: parentWordId,
                title
            })
        });

        const result = await response.json();
        if (!result.success) {
            const fallback = t('titleSaveFailed', 'Could not update title.');
            const detail = typeof result.data === 'string'
                ? result.data
                : (result.data && typeof result.data.message === 'string' ? result.data.message : fallback);
            throw new Error(detail || fallback);
        }

        if (!result.data || typeof result.data.title !== 'string') {
            return { title };
        }

        return result.data;
    }

    async function saveWordTitleFromBlock(block) {
        if (!block || state.saving) {
            return;
        }

        const parentWordId = parseInt(block.dataset.parentWordId, 10);
        const input = block.querySelector('.ll-word-title-input');
        if (!Number.isInteger(parentWordId) || parentWordId <= 0 || !input) {
            return;
        }

        const title = input.value.trim();
        if (!title) {
            alert(t('titleRequired', 'Title cannot be empty.'));
            input.focus();
            return;
        }

        if (state.titleSavingWordIds.has(parentWordId)) {
            return;
        }

        state.titleSavingWordIds.add(parentWordId);
        setWordTitleControlsDisabled(parentWordId, true);

        try {
            const payload = await saveWordTitle(parentWordId, title);
            const nextTitle = (payload && typeof payload.title === 'string' && payload.title.trim() !== '')
                ? payload.title.trim()
                : title;

            syncWordTitleAcrossState(parentWordId, nextTitle);
            applyWordTitleToDom(parentWordId, nextTitle);

            getWordTitleBlocks(parentWordId).forEach(closeWordTitleEditor);
        } catch (error) {
            alert(error && error.message ? error.message : t('titleSaveFailed', 'Could not update title.'));
        } finally {
            state.titleSavingWordIds.delete(parentWordId);
            setWordTitleControlsDisabled(parentWordId, false);
        }
    }

    function init() {
        if (!window.llAudioProcessor || !window.llAudioProcessor.recordings) {
            return;
        }
        state.recordings = window.llAudioProcessor.recordings;
        state.recordingTypes = Array.isArray(window.llAudioProcessor.recordingTypes) ? window.llAudioProcessor.recordingTypes : [];
        state.recordingTypeIcons = (window.llAudioProcessor.recordingTypeIcons && typeof window.llAudioProcessor.recordingTypeIcons === 'object')
            ? window.llAudioProcessor.recordingTypeIcons
            : {};
        state.audioContext = new (window.AudioContext || window.webkitAudioContext)();
        initTabs();
        wireEventListeners();
        updateSelectedCount();
    }

    function initTabs() {
        const tabsWrapper = document.querySelector('.ll-audio-processor-tabs');
        const tabButtons = document.querySelectorAll('.ll-audio-processor-tab');

        if (!tabsWrapper || tabButtons.length === 0) {
            return;
        }

        const initialTab = tabsWrapper.dataset.initialTab || tabButtons[0].dataset.tab || 'queue';
        setActiveTab(initialTab, { skipClear: true });

        tabButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const tab = btn.dataset.tab;
                if (!tab) {
                    return;
                }
                setActiveTab(tab);
            });
        });
    }

    function setActiveTab(tab, options = {}) {
        if (!tab || tab === state.activeTab) {
            return;
        }

        state.activeTab = tab;

        document.querySelectorAll('.ll-audio-processor-tab').forEach(btn => {
            const isActive = btn.dataset.tab === tab;
            btn.classList.toggle('is-active', isActive);
            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        document.querySelectorAll('.ll-recordings-list').forEach(list => {
            const isActive = list.dataset.tab === tab;
            list.classList.toggle('is-active', isActive);
            list.setAttribute('aria-hidden', isActive ? 'false' : 'true');
        });

        state.lastSelectedIndexByTab[tab] = null;

        if (!options.skipClear) {
            clearSelections();
        }
    }

    function clearSelections() {
        document.querySelectorAll('.ll-recording-checkbox').forEach(cb => {
            cb.checked = false;
        });
        state.selected.clear();
        updateSelectedCount();
    }

    function getActiveCheckboxes() {
        const list = document.querySelector(`.ll-recordings-list[data-tab="${state.activeTab}"]`);
        if (!list) {
            return [];
        }
        return Array.from(list.querySelectorAll('.ll-recording-checkbox'));
    }

    function wireEventListeners() {
        const selectAll = document.getElementById('ll-select-all');
        const deselectAll = document.getElementById('ll-deselect-all');
        const processBtn = document.getElementById('ll-process-selected');
        const deleteBtn = document.getElementById('ll-delete-selected');
        const checkboxes = document.querySelectorAll('.ll-recording-checkbox');

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
                const activeCheckboxes = getActiveCheckboxes();
                activeCheckboxes.forEach(cb => {
                    cb.checked = true;
                    state.selected.add(parseInt(cb.value));
                });
                updateSelectedCount();
            });
        }

        if (deselectAll) {
            deselectAll.addEventListener('click', () => {
                const activeCheckboxes = getActiveCheckboxes();
                activeCheckboxes.forEach(cb => {
                    cb.checked = false;
                    state.selected.delete(parseInt(cb.value));
                });
                updateSelectedCount();
            });
        }

        checkboxes.forEach(cb => {
            // Support single toggle updates
            cb.addEventListener('change', (e) => {
                const id = parseInt(e.target.value);
                if (e.target.checked) {
                    state.selected.add(id);
                } else {
                    state.selected.delete(id);
                }
                updateSelectedCount();
            });

            // Add shift-click range selection (Gmail-style)
            cb.addEventListener('click', (e) => {
                const list = e.currentTarget.closest('.ll-recordings-list');
                const tab = list && list.dataset.tab ? list.dataset.tab : state.activeTab;
                const cbArray = list ? Array.from(list.querySelectorAll('.ll-recording-checkbox')) : [];
                const currentIndex = cbArray.indexOf(e.currentTarget);
                const lastIndex = Number.isInteger(state.lastSelectedIndexByTab[tab])
                    ? state.lastSelectedIndexByTab[tab]
                    : null;

                if (e.shiftKey && lastIndex !== null && lastIndex !== -1) {
                    const start = Math.min(lastIndex, currentIndex);
                    const end = Math.max(lastIndex, currentIndex);
                    const shouldCheck = e.currentTarget.checked;

                    for (let i = start; i <= end; i++) {
                        const targetCb = cbArray[i];
                        if (!targetCb) continue;

                        // Only update if state differs to avoid unnecessary work
                        if (targetCb.checked !== shouldCheck) {
                            targetCb.checked = shouldCheck;
                        }

                        const id = parseInt(targetCb.value);
                        if (shouldCheck) {
                            state.selected.add(id);
                        } else {
                            state.selected.delete(id);
                        }
                    }

                    updateSelectedCount();
                }

                // Always remember the last clicked index
                state.lastSelectedIndexByTab[tab] = currentIndex;
            });
        });

        if (processBtn) {
            processBtn.addEventListener('click', processSelectedRecordings);
        }

        if (deleteBtn) {
            deleteBtn.addEventListener('click', deleteSelectedRecordings);
        }

        document.addEventListener('click', (e) => {
            if (e.target.id === 'll-save-all') {
                saveAllProcessedAudio();
            } else if (e.target.id === 'll-cancel-review') {
                cancelReview();
            } else if (e.target.id === 'll-delete-all-review') {
                deleteAllReviewRecordings();
            } else if (e.target.classList.contains('ll-remove-review-btn') || e.target.closest('.ll-remove-review-btn')) {
                const btn = e.target.classList.contains('ll-remove-review-btn') ? e.target : e.target.closest('.ll-remove-review-btn');
                const postId = parseInt(btn.dataset.postId);
                removeReviewRecording(postId);
            } else if (e.target.classList.contains('ll-delete-review-btn') || e.target.closest('.ll-delete-review-btn')) {
                const btn = e.target.classList.contains('ll-delete-review-btn') ? e.target : e.target.closest('.ll-delete-review-btn');
                const postId = parseInt(btn.dataset.postId);
                deleteReviewRecording(postId);
            } else if (e.target.classList.contains('ll-delete-recording') || e.target.closest('.ll-delete-recording')) {
                const btn = e.target.classList.contains('ll-delete-recording') ? e.target : e.target.closest('.ll-delete-recording');
                const postId = parseInt(btn.dataset.id);
                deleteRecording(postId, btn);
            } else if (e.target.classList.contains('ll-edit-word-title-btn') || e.target.closest('.ll-edit-word-title-btn')) {
                const btn = e.target.classList.contains('ll-edit-word-title-btn') ? e.target : e.target.closest('.ll-edit-word-title-btn');
                const block = btn.closest('.ll-word-title-block');
                openWordTitleEditor(block);
            } else if (e.target.classList.contains('ll-cancel-word-title-btn') || e.target.closest('.ll-cancel-word-title-btn')) {
                const btn = e.target.classList.contains('ll-cancel-word-title-btn') ? e.target : e.target.closest('.ll-cancel-word-title-btn');
                const block = btn.closest('.ll-word-title-block');
                closeWordTitleEditor(block);
            } else if (e.target.classList.contains('ll-save-word-title-btn') || e.target.closest('.ll-save-word-title-btn')) {
                const btn = e.target.classList.contains('ll-save-word-title-btn') ? e.target : e.target.closest('.ll-save-word-title-btn');
                const block = btn.closest('.ll-word-title-block');
                saveWordTitleFromBlock(block);
            }
        });

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
            } else if (e.target.classList.contains('ll-recording-type-select')) {
                const postId = parseInt(e.target.dataset.postId);
                const data = state.reviewData.get(postId);
                if (data) {
                    data.selectedRecordingType = e.target.value;
                }
            }
        });

        document.addEventListener('keydown', (e) => {
            if (!e.target || !e.target.classList || !e.target.classList.contains('ll-word-title-input')) {
                return;
            }

            if (e.key === 'Enter') {
                e.preventDefault();
                const block = e.target.closest('.ll-word-title-block');
                saveWordTitleFromBlock(block);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                const block = e.target.closest('.ll-word-title-block');
                closeWordTitleEditor(block);
            }
        });
    }

    function updateSelectedCount() {
        const countEl = document.getElementById('ll-selected-count');
        const processBtn = document.getElementById('ll-process-selected');
        const deleteBtn = document.getElementById('ll-delete-selected');
        const deleteCountEl = document.getElementById('ll-delete-selected-count');
        const isBusy = state.processing || state.deleting || state.saving;
        if (countEl) {
            countEl.textContent = state.selected.size;
        }
        if (deleteCountEl) {
            deleteCountEl.textContent = state.selected.size;
        }
        if (processBtn) {
            processBtn.disabled = state.selected.size === 0 || isBusy;
        }
        if (deleteBtn) {
            deleteBtn.disabled = state.selected.size === 0 || isBusy;
        }
    }

    async function processSelectedRecordings() {
        if (state.processing || state.saving || state.selected.size === 0) return;

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

                state.reviewData.set(recording.id, {
                    recording: recording,
                    originalBuffer: processedData.originalBuffer,
                    processedBuffer: processedData.processedBuffer,
                    trimStart: processedData.trimStart,
                    trimEnd: processedData.trimEnd,
                    options: { ...state.globalOptions },
                    selectedRecordingType: recording.recordingType || ''
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
        const response = await fetch(url);
        const arrayBuffer = await response.arrayBuffer();
        const originalBuffer = await state.audioContext.decodeAudioData(arrayBuffer.slice(0));

        let processedBuffer = originalBuffer;
        let trimStart = 0;
        let trimEnd = originalBuffer.length;

        if (options.enableTrim) {
            const trimResult = detectSilenceBoundaries(originalBuffer);
            trimStart = trimResult.start;
            trimEnd = trimResult.end;
            processedBuffer = trimSilence(originalBuffer, trimStart, trimEnd);
        }

        if (options.enableNoise) {
            processedBuffer = await reduceNoise(processedBuffer);
        }

        if (options.enableLoudness) {
            processedBuffer = await normalizeLoudness(processedBuffer);
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

    async function normalizeLoudness(audioBuffer) {
        const currentLUFS = calculateLUFS(audioBuffer);
        const targetGain = Math.pow(10, (TARGET_LUFS - currentLUFS) / 20);

        const normalizedBuffer = state.audioContext.createBuffer(
            audioBuffer.numberOfChannels,
            audioBuffer.length,
            audioBuffer.sampleRate
        );

        for (let channel = 0; channel < audioBuffer.numberOfChannels; channel++) {
            const sourceData = audioBuffer.getChannelData(channel);
            const targetData = normalizedBuffer.getChannelData(channel);
            for (let i = 0; i < sourceData.length; i++) {
                targetData[i] = Math.max(-1, Math.min(1, sourceData[i] * targetGain));
            }
        }

        return normalizedBuffer;
    }

    function calculateLUFS(audioBuffer) {
        const sampleRate = audioBuffer.sampleRate;
        const channelData = audioBuffer.getChannelData(0);

        const blockSize = Math.floor(0.4 * sampleRate);
        const hopSize = Math.floor(0.1 * sampleRate);

        const gatingThreshold = -70;
        const relativeThreshold = -10;

        let blockLoudnesses = [];

        for (let i = 0; i + blockSize < channelData.length; i += hopSize) {
            let sumSquares = 0;
            for (let j = 0; j < blockSize; j++) {
                const sample = channelData[i + j];
                sumSquares += sample * sample;
            }

            const meanSquare = sumSquares / blockSize;
            const loudness = -0.691 + 10 * Math.log10(meanSquare);

            if (loudness > gatingThreshold) {
                blockLoudnesses.push(loudness);
            }
        }

        if (blockLoudnesses.length === 0) {
            return -70;
        }

        const avgLoudness = blockLoudnesses.reduce((a, b) => a + b, 0) / blockLoudnesses.length;
        const relThreshold = avgLoudness + relativeThreshold;

        const gatedLoudnesses = blockLoudnesses.filter(l => l >= relThreshold);

        if (gatedLoudnesses.length === 0) {
            return avgLoudness;
        }

        const gatedAvg = gatedLoudnesses.reduce((a, b) => a + b, 0) / gatedLoudnesses.length;
        return gatedAvg;
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

        const recordingsList = document.querySelector('.ll-recordings-list');
        const tabs = document.querySelector('.ll-audio-processor-tabs');
        const processorControls = document.querySelector('.ll-processor-controls');
        const processingOptions = document.querySelector('.ll-processing-options');

        if (recordingsList) {
            document.querySelectorAll('.ll-recordings-list').forEach(list => {
                list.style.display = 'none';
            });
        }
        if (tabs) tabs.style.display = 'none';
        if (processorControls) processorControls.style.display = 'none';
        if (processingOptions) processingOptions.style.display = 'none';

        reviewInterface.style.display = 'block';
        reviewInterface.scrollIntoView({ behavior: 'smooth' });
    }

    function createReviewFileElement(postId, data) {
        const div = document.createElement('div');
        div.className = 'll-review-file';
        div.dataset.postId = postId;
        div.dataset.parentWordId = data && data.recording ? parseInt(data.recording.parentWordId, 10) || '' : '';

        const { recording, originalBuffer, processedBuffer, trimStart, trimEnd, options } = data;

        const imageHtml = recording.imageUrl
            ? `<img src="${escapeHtml(recording.imageUrl)}" alt="${escapeHtml(recording.title)}" class="ll-review-thumbnail">`
            : '';

        const selectedRecordingType = data.selectedRecordingType || recording.recordingType || '';
        const recordingTypeSelect = renderRecordingTypeSelect(selectedRecordingType, postId);

        const wordsetHtml = recording.wordsets && recording.wordsets.length > 0
            ? `<span class="ll-review-wordset"><strong>Wordset:</strong> ${escapeHtml(recording.wordsets.join(', '))}</span>`
            : '';

        const categoryHtml = recording.categories && recording.categories.length > 0
            ? `<span class="ll-review-category"><strong>Category:</strong> ${escapeHtml(recording.categories.join(', '))}</span>`
            : '';

        const titleInputLabel = t('titleInputLabel', 'Word title');
        const titleInputPlaceholder = t('titleInputPlaceholder', 'Enter word title');
        const editTitleButtonLabel = t('editTitleButton', 'Edit title');
        const saveTitleButtonLabel = t('saveTitleButton', 'Save title');
        const cancelTitleButtonLabel = t('cancelTitleButton', 'Cancel');

        div.innerHTML = `
            <div class="ll-review-header">
                <div class="ll-review-title-section">
                    ${imageHtml}
                    <div class="ll-review-title-info">
                        <div class="ll-word-title-block" data-parent-word-id="${parseInt(recording.parentWordId, 10) || ''}">
                            <div class="ll-word-title-display-row">
                                <h3 class="ll-review-title ll-recording-title-text">${escapeHtml(recording.title)}</h3>
                                <button type="button" class="ll-edit-word-title-btn button-link">${escapeHtml(editTitleButtonLabel)}</button>
                            </div>
                            <div class="ll-word-title-editor" hidden>
                                <label class="screen-reader-text" for="ll-review-word-title-input-${postId}">${escapeHtml(titleInputLabel)}</label>
                                <input
                                    id="ll-review-word-title-input-${postId}"
                                    type="text"
                                    class="ll-word-title-input"
                                    value="${escapeHtml(recording.title)}"
                                    placeholder="${escapeHtml(titleInputPlaceholder)}"
                                    maxlength="200"
                                >
                                <button type="button" class="button button-small ll-save-word-title-btn">${escapeHtml(saveTitleButtonLabel)}</button>
                                <button type="button" class="button button-small ll-cancel-word-title-btn">${escapeHtml(cancelTitleButtonLabel)}</button>
                            </div>
                        </div>
                        <div class="ll-review-metadata">
                            ${categoryHtml}
                            ${wordsetHtml}
                        </div>
                    </div>
                </div>
                <div class="ll-review-header-actions">
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
                    <div class="ll-review-options">
                        ${recordingTypeSelect}
                    </div>
                    <button class="ll-btn-secondary ll-remove-review-btn" data-post-id="${postId}" type="button" title="Remove from this batch">
                        Remove
                    </button>
                    <button class="button button-link-delete ll-delete-review-btn" data-post-id="${postId}" title="Delete this recording">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                            <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/>
                        </svg>
                        Delete
                    </button>
                </div>
            </div>
            <div class="ll-waveform-container" data-post-id="${postId}">
                <canvas class="ll-waveform-canvas"></canvas>
            </div>
            <div class="ll-playback-controls">
                <audio controls preload="auto"></audio>
            </div>
        `;

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

        addTrimBoundaries(waveformContainer, trimStart, trimEnd, audioBuffer.length);
    }

    function addTrimBoundaries(container, trimStart, trimEnd, totalSamples) {
        const startPercent = (trimStart / totalSamples) * 100;
        const endPercent = (trimEnd / totalSamples) * 100;

        container.querySelectorAll('.ll-trim-boundary, .ll-trimmed-region').forEach(el => el.remove());

        const startBoundary = document.createElement('div');
        startBoundary.className = 'll-trim-boundary ll-start';
        startBoundary.style.left = startPercent + '%';
        startBoundary.dataset.position = trimStart;
        container.appendChild(startBoundary);

        const endBoundary = document.createElement('div');
        endBoundary.className = 'll-trim-boundary ll-end';
        endBoundary.style.left = endPercent + '%';
        endBoundary.dataset.position = trimEnd;
        container.appendChild(endBoundary);

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

            let constrainedPercent = percent;
            if (isStart && percent >= otherPercent - 1) {
                constrainedPercent = otherPercent - 1;
            } else if (!isStart && percent <= otherPercent + 1) {
                constrainedPercent = otherPercent + 1;
            }

            currentBoundary.style.left = constrainedPercent + '%';
            const samplePosition = Math.floor((constrainedPercent / 100) * audioBuffer.length);
            currentBoundary.dataset.position = samplePosition;

            updateTrimmedRegions(waveformContainer, startBoundary, endBoundary);
            e.preventDefault();
        };

        const endDrag = () => {
            if (!isDragging) return;
            isDragging = false;
            waveformContainer.style.cursor = '';

            const newTrimStart = parseInt(startBoundary.dataset.position);
            const newTrimEnd = parseInt(endBoundary.dataset.position);

            const data = state.reviewData.get(postId);
            if (data) {
                data.trimStart = newTrimStart;
                data.trimEnd = newTrimEnd;
                data.manualBoundaries = true;
                updateProcessedAudio(postId, data);
            }

            currentBoundary = null;
            containerRect = null;
        };

        startBoundary.addEventListener('mousedown', (e) => startDrag(e, startBoundary));
        endBoundary.addEventListener('mousedown', (e) => startDrag(e, endBoundary));
        document.addEventListener('mousemove', onDrag);
        document.addEventListener('mouseup', endDrag);

        startBoundary.addEventListener('touchstart', (e) => startDrag(e, startBoundary));
        endBoundary.addEventListener('touchstart', (e) => startDrag(e, endBoundary));
        document.addEventListener('touchmove', onDrag, { passive: false });
        document.addEventListener('touchend', endDrag);

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

        container.querySelectorAll('.ll-trimmed-region').forEach(el => el.remove());

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

        audioElement.style.opacity = '0.5';

        try {
            const enableTrim = container.querySelector('.ll-file-trim').checked;
            const enableNoise = container.querySelector('.ll-file-noise').checked;
            const enableLoudness = container.querySelector('.ll-file-loudness').checked;

            let trimStart = data.trimStart;
            let trimEnd = data.trimEnd;
            let shouldRerender = false;

            if (enableTrim && !data.manualBoundaries && (!data.options || !data.options.enableTrim)) {
                const detected = detectSilenceBoundaries(data.originalBuffer);
                trimStart = detected.start;
                trimEnd = detected.end;
                data.trimStart = trimStart;
                data.trimEnd = trimEnd;
                shouldRerender = true;
            }

            let processedBuffer = data.originalBuffer;

            if (enableTrim) {
                processedBuffer = trimSilence(data.originalBuffer, trimStart, trimEnd);
            }

            if (enableNoise) {
                processedBuffer = await reduceNoise(processedBuffer);
            }

            if (enableLoudness) {
                processedBuffer = await normalizeLoudness(processedBuffer);
            }

            data.processedBuffer = processedBuffer;
            data.options = { enableTrim, enableNoise, enableLoudness };

            if (shouldRerender) {
                const waveformContainer = container.querySelector('.ll-waveform-container');
                if (waveformContainer) {
                    waveformContainer.querySelectorAll('.ll-trim-boundary, .ll-trimmed-region').forEach(el => el.remove());
                    renderWaveform(container, data.originalBuffer, trimStart, trimEnd);
                    setupBoundaryDragging(container, postId, data.originalBuffer);
                }
            }

            setupAudioPlayback(container, processedBuffer);
        } catch (error) {
            console.error('Error updating processed audio:', error);
        } finally {
            audioElement.style.opacity = '1';
        }
    }

    function audioBufferToWav(audioBuffer) {
        const numChannels = audioBuffer.numberOfChannels;
        const sampleRate = audioBuffer.sampleRate;
        const format = 1;
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

    async function deleteSelectedRecordings() {
        if (state.deleting || state.processing || state.saving || state.selected.size === 0) return;

        const postIds = Array.from(state.selected);

        if (!confirm(`Delete ${postIds.length} recording(s)? This action cannot be undone.`)) {
            return;
        }

        const deleteBtn = document.getElementById('ll-delete-selected');
        const deleteBtnLabel = deleteBtn ? deleteBtn.querySelector('.ll-btn-label') : null;

        state.deleting = true;
        if (deleteBtnLabel) {
            deleteBtnLabel.textContent = 'Deleting...';
        }
        updateSelectedCount();

        let deleted = 0;
        let failed = 0;

        for (const postId of postIds) {
            try {
                const success = await deleteRecordingById(postId);
                if (success) {
                    deleted++;
                    removeRecordingItem(postId);
                } else {
                    failed++;
                }
            } catch (error) {
                failed++;
            }
        }

        state.deleting = false;
        if (deleteBtnLabel) {
            deleteBtnLabel.textContent = 'Delete Selected';
        }
        updateSelectedCount();

        if (deleted > 0 && failed === 0) {
            alert(`Deleted ${deleted} recording(s).`);
        } else if (failed > 0) {
            alert(`Deleted ${deleted} recording(s). Failed to delete ${failed}.`);
        }

        const remainingItems = document.querySelectorAll('.ll-recording-item');
        if (remainingItems.length === 0) {
            location.reload();
        }
    }

    async function deleteRecordingById(postId) {
        const response = await fetch(window.llAudioProcessor.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'll_delete_audio_recording',
                nonce: window.llAudioProcessor.nonce,
                post_id: postId
            })
        });

        const data = await response.json();
        return !!data.success;
    }

    function removeRecordingItem(postId) {
        const item = document.querySelector(`.ll-recording-item[data-id="${postId}"]`);
        if (item) {
            item.remove();
        }
        state.selected.delete(postId);
        state.recordings = state.recordings.filter(recording => recording.id !== postId);
    }

    function deleteRecording(postId, button) {
        if (state.saving) return;

        const item = document.querySelector(`.ll-recording-item[data-id="${postId}"]`);
        if (!item) return;

        const title = item.querySelector('strong')?.textContent || 'this recording';

        if (!confirm(`Delete "${title}"? This action cannot be undone.`)) {
            return;
        }

        button.disabled = true;
        button.style.opacity = '0.5';

        fetch(window.llAudioProcessor.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'll_delete_audio_recording',
                nonce: window.llAudioProcessor.nonce,
                post_id: postId
            })
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    item.style.opacity = '0';
                    item.style.transform = 'translateX(-20px)';
                    item.style.transition = 'all 0.3s ease';

                    setTimeout(() => {
                        item.remove();

                        const checkbox = item.querySelector('.ll-recording-checkbox');
                        if (checkbox && checkbox.checked) {
                            state.selected.delete(postId);
                            updateSelectedCount();
                        }

                        const remainingItems = document.querySelectorAll('.ll-recording-item');
                        if (remainingItems.length === 0) {
                            location.reload();
                        }
                    }, 300);
                } else {
                    alert('Error: ' + (data.data || 'Failed to delete recording'));
                    button.disabled = false;
                    button.style.opacity = '1';
                }
            })
            .catch(error => {
                console.error('Delete error:', error);
                alert('Error deleting recording: ' + error.message);
                button.disabled = false;
                button.style.opacity = '1';
            });
    }

    function deleteReviewRecording(postId) {
        if (state.saving) return;

        const reviewFile = document.querySelector(`.ll-review-file[data-post-id="${postId}"]`);
        if (!reviewFile) return;

        const data = state.reviewData.get(postId);
        const title = data ? data.recording.title : 'this recording';

        if (!confirm(`Delete "${title}"? This action cannot be undone.`)) {
            return;
        }

        const deleteBtn = reviewFile.querySelector('.ll-delete-review-btn');
        if (deleteBtn) {
            deleteBtn.disabled = true;
            deleteBtn.style.opacity = '0.5';
        }

        fetch(window.llAudioProcessor.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'll_delete_audio_recording',
                nonce: window.llAudioProcessor.nonce,
                post_id: postId
            })
        })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    reviewFile.style.opacity = '0';
                    reviewFile.style.transform = 'translateY(-10px)';
                    reviewFile.style.transition = 'all 0.3s ease';

                    setTimeout(() => {
                        reviewFile.remove();
                        state.reviewData.delete(postId);

                        if (state.reviewData.size === 0) {
                            location.reload();
                        }
                    }, 300);
                } else {
                    alert('Error: ' + (result.data || 'Failed to delete recording'));
                    if (deleteBtn) {
                        deleteBtn.disabled = false;
                        deleteBtn.style.opacity = '1';
                    }
                }
            })
            .catch(error => {
                console.error('Delete error:', error);
                alert('Error deleting recording: ' + error.message);
                if (deleteBtn) {
                    deleteBtn.disabled = false;
                    deleteBtn.style.opacity = '1';
                }
            });
    }

    function removeReviewRecording(postId) {
        if (state.saving) return;

        const reviewFile = document.querySelector(`.ll-review-file[data-post-id="${postId}"]`);
        if (!reviewFile) return;

        const data = state.reviewData.get(postId);
        const title = data ? data.recording.title : 'this recording';

        if (!confirm(`Remove "${title}" from this batch? It will remain unprocessed.`)) {
            return;
        }

        reviewFile.style.opacity = '0';
        reviewFile.style.transform = 'translateY(-10px)';
        reviewFile.style.transition = 'all 0.3s ease';

        setTimeout(() => {
            reviewFile.remove();
            state.reviewData.delete(postId);

            if (state.reviewData.size === 0) {
                location.reload();
            }
        }, 300);
    }

    function deleteAllReviewRecordings() {
        if (state.saving) return;

        const count = state.reviewData.size;
        if (count === 0) return;

        if (!confirm(`Delete all ${count} recording(s)? This action cannot be undone.`)) {
            return;
        }

        const deleteBtn = document.getElementById('ll-delete-all-review');
        if (deleteBtn) {
            deleteBtn.disabled = true;
            deleteBtn.textContent = 'Deleting...';
        }

        const postIds = Array.from(state.reviewData.keys());
        let deleted = 0;
        let failed = 0;

        Promise.all(postIds.map(postId => {
            return fetch(window.llAudioProcessor.ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'll_delete_audio_recording',
                    nonce: window.llAudioProcessor.nonce,
                    post_id: postId
                })
            })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        deleted++;
                    } else {
                        failed++;
                    }
                })
                .catch(() => {
                    failed++;
                });
        }))
            .then(() => {
                if (failed === 0) {
                    alert(`Successfully deleted ${deleted} recording(s)`);
                    location.reload();
                } else {
                    alert(`Deleted ${deleted} recording(s). Failed to delete ${failed}.`);
                    if (deleteBtn) {
                        deleteBtn.disabled = false;
                        deleteBtn.textContent = 'Delete All';
                    }
                }
            });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function getRecordingTypeIcon(slug) {
        if (slug && state.recordingTypeIcons[slug]) {
            return state.recordingTypeIcons[slug];
        }
        return state.recordingTypeIcons.default || '';
    }

    function getRecordingTypeDisplay(type) {
        if (!type || typeof type !== 'object') {
            return '';
        }
        if (type.label) {
            return String(type.label);
        }
        const icon = type.icon || getRecordingTypeIcon(type.slug);
        const label = type.name || type.slug || '';
        return icon ? `${icon} ${label}` : String(label);
    }

    function renderRecordingTypeSelect(selectedType, postId) {
        const options = [];

        if (state.recordingTypes.length === 0) {
            options.push('<option value="">No recording types found</option>');
        } else {
            options.push('<option value="">Select type</option>');
            state.recordingTypes.forEach(type => {
                const isSelected = type.slug === selectedType;
                const display = getRecordingTypeDisplay(type);
                options.push(`<option value="${escapeHtml(type.slug)}" ${isSelected ? 'selected' : ''}>${escapeHtml(display)}</option>`);
            });

            const slugExists = state.recordingTypes.some(type => type.slug === selectedType);
            if (selectedType && !slugExists) {
                const fallbackType = {
                    slug: selectedType,
                    name: selectedType,
                    icon: getRecordingTypeIcon(selectedType)
                };
                options.push(`<option value="${escapeHtml(selectedType)}" selected>${escapeHtml(getRecordingTypeDisplay(fallbackType))}</option>`);
            }
        }

        return `
            <label class="ll-recording-type-label">
                <span>Recording Type</span>
                <select class="ll-recording-type-select" data-post-id="${postId}">
                    ${options.join('')}
                </select>
            </label>
        `;
    }

    async function saveAllProcessedAudio() {
        if (state.saving) {
            return;
        }

        if (state.reviewData.size === 0) {
            alert(t('saveNoFiles', 'No files to save.'));
            return;
        }

        const saveBtn = document.getElementById('ll-save-all');
        const cancelBtn = document.getElementById('ll-cancel-review');
        if (!saveBtn || !cancelBtn) {
            return;
        }

        const saveConfirmTemplate = t('saveConfirmTemplate', 'Save %d processed audio file(s)?');
        if (!confirm(formatText(saveConfirmTemplate, [state.reviewData.size]))) {
            return;
        }

        const total = state.reviewData.size;
        let saved = 0;
        let failed = 0;

        setSavingState(true);
        showSaveOverlay(total);

        saveBtn.disabled = true;
        cancelBtn.disabled = true;
        saveBtn.textContent = t('saveButtonSaving', 'Saving...');

        try {
            for (const [postId, data] of state.reviewData) {
                try {
                    const index = saved + failed + 1;
                    const savingTemplate = t('saveStatusTemplate', 'Saving: %1$s (%2$d/%3$d)');
                    setSaveOverlayState(formatText(savingTemplate, [data.recording.title, index, total]), saved + failed, total);

                    // Apply second pass of loudness normalization before saving
                    let finalBuffer = data.processedBuffer;
                    if (data.options.enableLoudness) {
                        finalBuffer = await normalizeLoudness(data.processedBuffer);
                    }

                    await saveProcessedAudio(postId, data, finalBuffer);
                    saved++;

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

                setSaveOverlayState(
                    formatText(t('saveStatusTemplate', 'Saving: %1$s (%2$d/%3$d)'), [
                        data.recording.title,
                        Math.min(saved + failed, total),
                        total
                    ]),
                    saved + failed,
                    total
                );
            }

            if (failed === 0) {
                const successTemplate = t('saveSuccessTemplate', 'Success! Saved %1$d of %2$d files.');
                setSaveOverlayState(formatText(successTemplate, [saved, total]), total, total, 'success');

                setTimeout(() => {
                    setSavingState(false);
                    location.reload();
                }, 900);
            } else {
                const errorTemplate = t('saveErrorSummaryTemplate', 'Completed with errors: %1$d saved, %2$d failed.');
                setSaveOverlayState(formatText(errorTemplate, [saved, failed]), total, total, 'error');

                setTimeout(() => {
                    setSavingState(false);
                    hideSaveOverlay();
                    saveBtn.disabled = false;
                    cancelBtn.disabled = false;
                    saveBtn.textContent = t('saveButtonDefault', 'Save All Changes');
                }, 1200);
            }
        } catch (error) {
            console.error('Unexpected save flow failure:', error);
            setSaveOverlayState(t('saveUnexpectedError', 'Unexpected error while saving. Please try again.'), saved + failed, total, 'error');

            setTimeout(() => {
                setSavingState(false);
                hideSaveOverlay();
                saveBtn.disabled = false;
                cancelBtn.disabled = false;
                saveBtn.textContent = t('saveButtonDefault', 'Save All Changes');
            }, 1200);
        }
    }

    async function saveProcessedAudio(postId, data, finalBuffer) {
        const { recording } = data;

        let audioBlob;
        let filename;

        if (window.lamejs) {
            audioBlob = await encodeToMP3(finalBuffer);
            filename = `${sanitizeFilename(recording.title)}_processed.mp3`;
        } else {
            audioBlob = audioBufferToWav(finalBuffer);
            filename = `${sanitizeFilename(recording.title)}_processed.wav`;
        }

        const formData = new FormData();
        formData.append('action', 'll_save_processed_audio');
        formData.append('nonce', window.llAudioProcessor.nonce);
        formData.append('post_id', postId);
        formData.append('audio', audioBlob, filename);

        const recordingType = data.selectedRecordingType || data.recording.recordingType || '';
        formData.append('recording_type', recordingType);

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
        if (state.saving) {
            return;
        }
        if (confirm('Are you sure you want to cancel? All processing will be lost.')) {
            location.reload();
        }
    }
})();
