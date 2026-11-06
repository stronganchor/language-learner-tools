(function (global) {
    'use strict';

    // This renderer deliberately knows nothing about WordPress or recording provenance.
    // Its caller supplies reviewed labels and explicitly selected original recordings.
    const MAX_ITEMS = 30;
    const MAX_FILE_BYTES = 15 * 1024 * 1024;
    const MAX_DOWNLOAD_BYTES = 180 * 1024 * 1024;
    const MAX_AUDIO_BYTES = 96 * 1024 * 1024;
    const MAX_AUDIO_SECONDS = 30;
    const AUDIO_METADATA_TOLERANCE = 0.05;
    // Native audio decoding cannot be canceled, including across renderer replacement.
    let activeAudioDecode = null;
    const MAX_SECONDS = 600;
    const FETCH_TIMEOUT = 30000;
    const DECODE_TIMEOUT = 20000;
    const SIZES = { portrait: [1080, 1920], landscape: [1920, 1080], square: [1080, 1080] };

    function fail(code, item) {
        const error = new Error(code);
        error.code = code;
        if (item) {
            error.itemId = item.id;
            error.itemText = item.text;
        }
        return error;
    }

    function abortError(signal) {
        return signal && signal.reason && signal.reason.code ? signal.reason : fail('canceled');
    }

    function checkAbort(signal) {
        if (signal && signal.aborted) {
            throw abortError(signal);
        }
    }

    function bounded(promise, signal, milliseconds, code, item) {
        return new Promise(function (resolve, reject) {
            let settled = false;
            const finish = function (callback, value) {
                if (settled) { return; }
                settled = true;
                clearTimeout(timer);
                if (signal) { signal.removeEventListener('abort', abort); }
                callback(value);
            };
            const abort = function () { finish(reject, abortError(signal)); };
            const timer = setTimeout(function () { finish(reject, fail(code, item)); }, milliseconds);
            if (signal) {
                signal.addEventListener('abort', abort, { once: true });
                if (signal.aborted) { abort(); }
            }
            Promise.resolve(promise).then(function (value) { finish(resolve, value); }, function (error) { finish(reject, error); });
        });
    }

    async function readAudioDuration(blob, signal, item) {
        const source = document.createElement('audio');
        const url = URL.createObjectURL(blob);
        try {
            const metadata = new Promise(function (resolve, reject) {
                let sought = false;
                const read = function () {
                    if (Number.isFinite(source.duration) && source.duration > 0) {
                        resolve(source.duration);
                    } else if (source.duration === Infinity && source.readyState >= 1 && !sought) {
                        // Some browser recordings omit duration until the demuxer reaches the end.
                        sought = true;
                        try { source.currentTime = MAX_AUDIO_SECONDS + 1; }
                        catch (error) { reject(fail('audio_decode', item)); }
                    }
                };
                source.preload = 'metadata';
                source.onloadedmetadata = read;
                source.ondurationchange = read;
                source.onseeked = function () {
                    read();
                    if (source.duration === Infinity && source.currentTime > MAX_AUDIO_SECONDS) {
                        reject(fail('audio_too_long', item));
                    }
                };
                source.onerror = function () { reject(fail('audio_decode', item)); };
                source.src = url;
            });
            return await bounded(metadata, signal, DECODE_TIMEOUT, 'audio_decode', item);
        } finally {
            source.onloadedmetadata = null;
            source.ondurationchange = null;
            source.onseeked = null;
            source.onerror = null;
            source.removeAttribute('src');
            source.load();
            URL.revokeObjectURL(url);
        }
    }

    async function decodeAudio(blob, audioContext, signal, item, budgets) {
        const duration = await readAudioDuration(blob, signal, item);
        if (duration > MAX_AUDIO_SECONDS + AUDIO_METADATA_TOLERANCE) { throw fail('audio_too_long', item); }
        while (activeAudioDecode) {
            await bounded(activeAudioDecode, signal, DECODE_TIMEOUT, 'audio_decode', item);
        }
        checkAbort(signal);

        // Reject even a mono buffer if it cannot fit. Actual channels/bytes are checked before it is retained.
        const minimumSeconds = Math.max(0, duration - AUDIO_METADATA_TOLERANCE);
        if (budgets.audio + minimumSeconds * audioContext.sampleRate * 4 > MAX_AUDIO_BYTES) {
            throw fail('memory_limit', item);
        }
        if (budgets.seconds + minimumSeconds > MAX_SECONDS) { throw fail('project_too_long', item); }
        const decode = (async function () {
            try {
                const bytes = await blob.arrayBuffer();
                checkAbort(signal);
                return await audioContext.decodeAudioData(bytes);
            } catch (error) {
                if (signal.aborted) { throw abortError(signal); }
                throw fail('audio_decode', item);
            }
        }()).then(function (audio) {
            checkAbort(signal);
            if (!Number.isFinite(audio.duration) || audio.duration <= 0 || audio.duration > MAX_AUDIO_SECONDS) {
                throw fail('audio_too_long', item);
            }
            const audioBytes = budgets.audio + audio.length * audio.numberOfChannels * 4;
            const seconds = budgets.seconds + audio.duration;
            if (audioBytes > MAX_AUDIO_BYTES) { throw fail('memory_limit', item); }
            if (seconds > MAX_SECONDS) { throw fail('project_too_long', item); }
            budgets.audio = audioBytes;
            budgets.seconds = seconds;
            return audio;
        });
        const release = function () { activeAudioDecode = null; };
        activeAudioDecode = decode.then(release, release);
        return await bounded(decode, signal, DECODE_TIMEOUT, 'audio_decode', item);
    }

    function safeUrl(value, item) {
        try {
            const url = new URL(String(value || ''), global.location.href);
            if (!value || !['http:', 'https:', 'blob:'].includes(url.protocol)) { throw new Error(); }
            return url.href;
        } catch (error) {
            throw fail('invalid_items', item);
        }
    }

    function cleanOptions(input) {
        const options = Object.assign({ layout: 'portrait', repeats: 2, gap: 1.5, recall: 0, showText: true, title: '' }, input || {});
        options.repeats = Number(options.repeats);
        options.gap = Number(options.gap);
        options.recall = Number(options.recall);
        if (!SIZES[options.layout] || !Number.isInteger(options.repeats) || options.repeats < 1 || options.repeats > 3 ||
            !Number.isFinite(options.gap) || options.gap < 0.5 || options.gap > 5 ||
            !Number.isFinite(options.recall) || options.recall < 0 || options.recall > 5 ||
            typeof options.showText !== 'boolean' || typeof options.title !== 'string' || options.title.length > 300) {
            throw fail('invalid_options');
        }
        return options;
    }

    function roundBox(ctx, x, y, width, height, radius) {
        const r = Math.min(radius, width / 2, height / 2);
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + width, y, x + width, y + height, r);
        ctx.arcTo(x + width, y + height, x, y + height, r);
        ctx.arcTo(x, y + height, x, y, r);
        ctx.arcTo(x, y, x + width, y, r);
        ctx.closePath();
    }

    function graphemes(text) {
        if (global.Intl && global.Intl.Segmenter) {
            return Array.from(new global.Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(text), function (part) { return part.segment; });
        }
        return Array.from(text);
    }

    function wrap(ctx, text, width) {
        const lines = [];
        String(text).split(/\n/u).forEach(function (paragraph) {
            let line = '';
            paragraph.split(/\s+/u).filter(Boolean).forEach(function (word) {
                if (line && ctx.measureText(line + ' ' + word).width <= width) {
                    line += ' ' + word;
                    return;
                }
                if (line) { lines.push(line); line = ''; }
                if (ctx.measureText(word).width <= width) {
                    line = word;
                    return;
                }
                graphemes(word).forEach(function (letter) {
                    if (line && ctx.measureText(line + letter).width > width) { lines.push(line); line = ''; }
                    line += letter;
                });
            });
            lines.push(line);
        });
        return lines;
    }

    function label(ctx, text, x, y, width, height, maxSize, weight, color) {
        let size = maxSize;
        let lines;
        while (true) {
            ctx.font = weight + ' ' + size + 'px system-ui, -apple-system, "Segoe UI", sans-serif';
            lines = wrap(ctx, text, width);
            if (lines.length * size * 1.2 <= height || size <= 12) { break; }
            size = Math.max(12, size - 2);
        }
        ctx.fillStyle = color;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        const start = y + (height - lines.length * size * 1.2) / 2 + size * 0.6;
        lines.forEach(function (line, index) { ctx.fillText(line, x + width / 2, start + index * size * 1.2); });
    }

    function create(settings) {
        const canvas = settings && settings.canvas;
        const ctx = canvas && typeof canvas.getContext === 'function' ? canvas.getContext('2d', { alpha: false }) : null;
        const AudioContextClass = global.AudioContext || global.webkitAudioContext;
        if (!ctx || !AudioContextClass || !global.fetch || !global.AbortController) { throw fail('unsupported'); }
        const onProgress = typeof settings.onProgress === 'function' ? settings.onProgress : function () {};
        let context = null;
        let prepared = null;
        let active = null;
        let disposed = false;

        function progress(details) {
            // Consumer UI failures must never leave a recording running without an owner.
            try { onProgress(details); } catch (error) { /* Caller owns presentation errors. */ }
        }

        function assertLive() {
            if (disposed) { throw fail('disposed'); }
        }

        function releaseMedia(media) {
            (media || []).forEach(function (entry) {
                if (entry && entry.image) {
                    entry.image.width = 1;
                    entry.image.height = 1;
                    entry.image = null;
                }
                if (entry) { entry.audio = null; }
            });
        }

        function startOperation(signal) {
            const operation = { controller: new AbortController(), unlink: function () {}, cleanup: function () {} };
            if (signal) {
                const abort = function () { operation.controller.abort(abortError(signal)); };
                signal.addEventListener('abort', abort, { once: true });
                operation.unlink = function () { signal.removeEventListener('abort', abort); };
                if (signal.aborted) { abort(); }
            }
            active = operation;
            return operation;
        }

        function cancel() {
            if (active) {
                const operation = active;
                active = null;
                operation.controller.abort(fail('canceled'));
                operation.cleanup();
                operation.unlink();
            }
        }

        async function download(url, signal, item, budgets) {
            const controller = new AbortController();
            const abort = function () { controller.abort(abortError(signal)); };
            signal.addEventListener('abort', abort, { once: true });
            const timer = setTimeout(function () { controller.abort(fail('media_fetch', item)); }, FETCH_TIMEOUT);
            let reader = null;
            try {
                checkAbort(signal);
                const response = await fetch(url, { signal: controller.signal, credentials: 'same-origin', mode: 'cors', cache: 'default' });
                if (!response.ok) { throw fail('media_fetch', item); }
                const length = Number(response.headers.get('content-length'));
                if (length > MAX_FILE_BYTES) { throw fail('media_too_large', item); }
                if (!response.body || typeof response.body.getReader !== 'function') { throw fail('unsupported'); }
                reader = response.body.getReader();
                const chunks = [];
                let size = 0;
                while (true) {
                    const chunk = await reader.read();
                    if (chunk.done) { break; }
                    size += chunk.value.byteLength;
                    budgets.downloaded += chunk.value.byteLength;
                    if (size > MAX_FILE_BYTES) { throw fail('media_too_large', item); }
                    if (budgets.downloaded > MAX_DOWNLOAD_BYTES) { throw fail('memory_limit', item); }
                    chunks.push(chunk.value);
                }
                checkAbort(signal);
                if (!size) { throw fail('media_fetch', item); }
                return new Blob(chunks, { type: response.headers.get('content-type') || 'application/octet-stream' });
            } catch (error) {
                if (signal.aborted) { throw abortError(signal); }
                if (error.code && typeof error.code === 'string') { throw error; }
                throw fail('media_fetch', item);
            } finally {
                clearTimeout(timer);
                signal.removeEventListener('abort', abort);
                if (reader) { try { await reader.cancel(); } catch (error) { /* Already closed. */ } }
                controller.abort();
            }
        }

        async function decodeImage(blob, signal, item) {
            const source = new Image();
            const url = URL.createObjectURL(blob);
            try {
                const loaded = new Promise(function (resolve, reject) {
                    source.onload = resolve;
                    source.onerror = function () { reject(fail('image_decode', item)); };
                    source.src = url;
                });
                await bounded(loaded, signal, DECODE_TIMEOUT, 'image_decode', item);
                checkAbort(signal);
                if (!source.naturalWidth || !source.naturalHeight || source.naturalWidth * source.naturalHeight > 40000000) { throw fail('image_decode', item); }
                const image = document.createElement('canvas');
                const scale = Math.min(1, 1440 / Math.max(source.naturalWidth, source.naturalHeight));
                image.width = Math.max(1, Math.round(source.naturalWidth * scale));
                image.height = Math.max(1, Math.round(source.naturalHeight * scale));
                const imageContext = image.getContext('2d');
                if (!imageContext) { throw fail('image_decode', item); }
                imageContext.drawImage(source, 0, 0, image.width, image.height);
                return image;
            } finally {
                source.onload = null;
                source.onerror = null;
                source.src = '';
                URL.revokeObjectURL(url);
            }
        }

        async function prepare(items, inputOptions, control) {
            assertLive();
            cancel();
            releaseMedia(prepared && prepared.media);
            prepared = null;
            const options = cleanOptions(inputOptions);
            if (!Array.isArray(items) || !items.length || items.length > MAX_ITEMS) { throw fail('invalid_items'); }
            const normalized = items.map(function (item) {
                if (!item || typeof item.text !== 'string' || !item.text.trim() || item.text.length > 500) { throw fail('invalid_items', item); }
                return { id: item.id, text: item.text, image_url: safeUrl(item.image_url, item), audio_url: safeUrl(item.audio_url, item) };
            });
            if (!context || context.state === 'closed') { context = new AudioContextClass(); }
            const audioContext = context;
            const operation = startOperation(control && control.signal);
            const signal = operation.controller.signal;
            const media = new Array(items.length);
            const budgets = { downloaded: 0, audio: 0, seconds: 0 };
            let next = 0;
            let completed = 0;
            progress({ phase: 'loading', completed: 0, total: items.length, progress: 0 });
            const worker = async function () {
                while (next < normalized.length) {
                    checkAbort(signal);
                    const index = next++;
                    const item = normalized[index];
                    const entry = Object.assign({}, item, { image: null, audio: null });
                    media[index] = entry;
                    entry.image = await decodeImage(await download(item.image_url, signal, item, budgets), signal, item);
                    checkAbort(signal);
                    const audioBlob = await download(item.audio_url, signal, item, budgets);
                    entry.audio = await decodeAudio(audioBlob, audioContext, signal, item, budgets);
                    checkAbort(signal);
                    completed++;
                    progress({ phase: 'loading', completed: completed, total: items.length, progress: completed / items.length });
                }
            };
            try {
                // Catch at each worker to abort its peers promptly; allSettled waits for cleanup.
                const workers = Array.from({ length: Math.min(3, items.length) }, function () {
                    return worker().catch(function (error) {
                        if (!signal.aborted) { operation.controller.abort(error); }
                        throw error;
                    });
                });
                const results = await Promise.allSettled(workers);
                checkAbort(signal);
                const rejected = results.find(function (result) { return result.status === 'rejected'; });
                if (rejected) { throw rejected.reason; }
                let cursor = 0;
                const timeline = media.map(function (entry) {
                    const start = cursor;
                    const reveal = start + options.recall;
                    const sounds = [];
                    let soundTime = reveal + 0.35;
                    for (let repeat = 0; repeat < options.repeats; repeat++) {
                        sounds.push(soundTime);
                        soundTime += entry.audio.duration + (repeat + 1 < options.repeats ? options.gap : 0);
                    }
                    cursor = soundTime + Math.max(0.75, options.gap);
                    return { start: start, reveal: reveal, end: cursor, sounds: sounds };
                });
                if (cursor > MAX_SECONDS) { throw fail('project_too_long'); }
                checkAbort(signal);
                if (active !== operation || disposed) { throw fail('canceled'); }
                const size = SIZES[options.layout];
                canvas.width = size[0];
                canvas.height = size[1];
                prepared = { media: media, options: options, timeline: timeline, duration: cursor };
                paint(0, timeline[0].reveal);
                const result = { duration: cursor, itemCount: media.length, width: size[0], height: size[1] };
                progress(Object.assign({ phase: 'ready', completed: media.length, total: media.length, progress: 1 }, result));
                checkAbort(signal);
                return result;
            } catch (error) {
                releaseMedia(media);
                throw error;
            } finally {
                operation.unlink();
                if (active === operation) { active = null; }
            }
        }

        function paint(index, elapsed) {
            const entry = prepared.media[index];
            const options = prepared.options;
            const time = prepared.timeline[index];
            const width = canvas.width;
            const height = canvas.height;
            const landscape = options.layout === 'landscape';
            const square = options.layout === 'square';
            const pad = 72;
            ctx.fillStyle = '#f6f3eb';
            ctx.fillRect(0, 0, width, height);
            ctx.fillStyle = '#2e7269';
            roundBox(ctx, pad, 64, 56, 9, 4);
            ctx.fill();
            if (options.title) { label(ctx, options.title, pad + 85, 34, width - pad * 2 - 200, square ? 70 : 90, 34, '600', '#42625c'); }
            label(ctx, String(index + 1) + ' / ' + String(prepared.media.length), width - pad - 100, 34, 100, 70, 29, '500', '#42625c');

            const box = landscape ? { x: pad, y: 170, w: 1060, h: 744 } : square ? { x: pad, y: 142, w: width - pad * 2, h: 610 } : { x: pad, y: 208, w: width - pad * 2, h: 1175 };
            ctx.fillStyle = '#ffffff';
            roundBox(ctx, box.x, box.y, box.w, box.h, 32);
            ctx.fill();
            const inset = 32;
            const scale = Math.min((box.w - inset * 2) / entry.image.width, (box.h - inset * 2) / entry.image.height);
            const imageWidth = entry.image.width * scale;
            const imageHeight = entry.image.height * scale;
            ctx.drawImage(entry.image, box.x + (box.w - imageWidth) / 2, box.y + (box.h - imageHeight) / 2, imageWidth, imageHeight);

            const textBox = landscape ? { x: 1196, y: 220, w: 652, h: 610 } : square ? { x: pad, y: 782, w: width - pad * 2, h: 188 } : { x: pad, y: 1435, w: width - pad * 2, h: 330 };
            const reveal = elapsed >= time.reveal;
            if (options.showText && reveal) {
                label(ctx, entry.text, textBox.x, textBox.y, textBox.w, textBox.h, square ? 66 : landscape ? 92 : 96, '700', '#17352f');
            } else if (!reveal) {
                // A shrinking ring gives recall time without language-specific UI copy.
                const ratio = Math.max(0, Math.min(1, (time.reveal - elapsed) / options.recall));
                const x = textBox.x + textBox.w / 2;
                const y = textBox.y + textBox.h / 2;
                ctx.strokeStyle = '#dbe5df';
                ctx.lineWidth = 8;
                ctx.beginPath(); ctx.arc(x, y, 31, 0, Math.PI * 2); ctx.stroke();
                ctx.strokeStyle = '#2e7269';
                ctx.beginPath(); ctx.arc(x, y, 31, -Math.PI / 2, -Math.PI / 2 + ratio * Math.PI * 2); ctx.stroke();
            }
            ctx.fillStyle = '#dbe5df';
            roundBox(ctx, pad, height - 59, width - pad * 2, 7, 3);
            ctx.fill();
            const fraction = Math.max(0, Math.min(1, elapsed / prepared.duration));
            if (fraction > 0) {
                ctx.fillStyle = '#2e7269';
                roundBox(ctx, pad, height - 59, (width - pad * 2) * fraction, 7, 3);
                ctx.fill();
            }
        }

        function draw(index) {
            assertLive();
            if (!prepared) { throw fail('not_prepared'); }
            if (active) { return; }
            const selected = index === undefined ? 0 : Number(index);
            if (!Number.isInteger(selected) || selected < 0 || selected >= prepared.media.length) { throw fail('invalid_items'); }
            paint(selected, prepared.timeline[selected].reveal);
        }

        function recordingMime() {
            if (!global.MediaRecorder || typeof global.MediaRecorder.isTypeSupported !== 'function' || typeof canvas.captureStream !== 'function') { throw fail('unsupported'); }
            // Level 4.0 accommodates 1080 x 1920 at 30 fps; Level 3.0 does not.
            const choices = ['video/mp4;codecs=avc1.420028,mp4a.40.2', 'video/mp4;codecs=avc1,mp4a.40.2', 'video/mp4', 'video/webm;codecs=vp8,opus', 'video/webm'];
            const mime = choices.find(function (candidate) { return global.MediaRecorder.isTypeSupported(candidate); });
            if (!mime) { throw fail('unsupported'); }
            return mime;
        }

        async function play(control) {
            assertLive();
            if (active) { throw fail('busy'); }
            if (!prepared) { throw fail('not_prepared'); }
            const record = !!(control && control.record);
            if (document.hidden) { throw fail('hidden'); }
            const mime = record ? recordingMime() : null;
            const operation = startOperation(control && control.signal);
            const signal = operation.controller.signal;
            const snapshot = prepared;
            const audioContext = context;
            const sources = [];
            const chunks = [];
            let stream = null;
            let capture = null;
            let destination = null;
            let recorder = null;
            let frame = 0;
            let watchdog = 0;
            let stopTimeout = 0;
            let startTimeout = 0;
            let cleaned = false;
            let startingAt = 0;
            let started = false;
            let lastProgress = -1;
            let capturedBytes = 0;
            let stopRequested = false;
            const maximumOutputBytes = 512 * 1024 * 1024;
            let rejectRun;

            const abort = function () { if (rejectRun) { rejectRun(abortError(signal)); } };
            const visibility = function () { if (document.hidden) { operation.controller.abort(fail('hidden')); } };
            const stateChange = function () {
                if (started && audioContext.state !== 'running') { operation.controller.abort(fail('audio_suspended')); }
            };
            const cleanup = function () {
                if (cleaned) { return; }
                cleaned = true;
                cancelAnimationFrame(frame);
                clearTimeout(watchdog);
                clearTimeout(stopTimeout);
                clearTimeout(startTimeout);
                signal.removeEventListener('abort', abort);
                document.removeEventListener('visibilitychange', visibility);
                audioContext.removeEventListener('statechange', stateChange);
                sources.forEach(function (source) {
                    try { source.stop(); } catch (error) { /* Already ended. */ }
                    source.disconnect();
                });
                if (recorder) {
                    recorder.ondataavailable = null;
                    recorder.onerror = null;
                    recorder.onstop = null;
                    recorder.onstart = null;
                    if (recorder.state !== 'inactive') { try { recorder.stop(); } catch (error) { /* Stop raced cancellation. */ } }
                }
                if (stream) { stream.getTracks().forEach(function (track) { track.stop(); }); }
                if (capture) { capture.getTracks().forEach(function (track) { track.stop(); }); }
                if (destination) { destination.disconnect(); destination.stream.getTracks().forEach(function (track) { track.stop(); }); }
                chunks.length = 0;
            };
            operation.cleanup = cleanup;
            signal.addEventListener('abort', abort, { once: true });
            document.addEventListener('visibilitychange', visibility);
            audioContext.addEventListener('statechange', stateChange);

            try {
                checkAbort(signal);
                // Called before the first await, so the caller's click can unlock Web Audio.
                await bounded(audioContext.resume(), signal, 10000, 'audio_suspended');
                checkAbort(signal);
                if (audioContext.state !== 'running') { throw fail('audio_suspended'); }
                if (active !== operation || prepared !== snapshot || disposed) { throw fail('canceled'); }
                paint(0, 0);
                const result = await new Promise(function (resolve, reject) {
                    rejectRun = reject;
                    const finish = function () {
                        if (record) {
                            if (!recorder || recorder.state !== 'recording') { reject(fail('recording_failed')); return; }
                            stopRequested = true;
                            stopTimeout = setTimeout(function () { reject(fail('recording_timeout')); }, 10000);
                            recorder.stop();
                        } else {
                            resolve({ duration: snapshot.duration });
                        }
                    };
                    const tick = function () {
                        if (signal.aborted || cleaned) { return; }
                        try {
                            const elapsed = Math.max(0, audioContext.currentTime - startingAt);
                            const index = Math.max(0, snapshot.timeline.findIndex(function (time) { return elapsed < time.end; }));
                            if (elapsed >= snapshot.duration) {
                                paint(snapshot.media.length - 1, snapshot.duration);
                                progress({ phase: record ? 'recording' : 'playing', completed: snapshot.media.length, total: snapshot.media.length, progress: 1, elapsed: snapshot.duration, duration: snapshot.duration, index: snapshot.media.length - 1 });
                                checkAbort(signal);
                                finish();
                                return;
                            }
                            paint(index, elapsed);
                            if (elapsed - lastProgress >= 0.1) {
                                lastProgress = elapsed;
                                progress({ phase: record ? 'recording' : 'playing', completed: index, total: snapshot.media.length, progress: elapsed / snapshot.duration, elapsed: elapsed, duration: snapshot.duration, index: index });
                            }
                            checkAbort(signal);
                            frame = requestAnimationFrame(tick);
                        } catch (error) { reject(error); }
                    };
                    const start = function () {
                        try {
                            checkAbort(signal);
                            if (started || cleaned) { return; }
                            started = true;
                            // Leave the encoder enough lead-in to capture the first still frame.
                            startingAt = audioContext.currentTime + 0.15;
                            snapshot.media.forEach(function (entry, index) {
                                snapshot.timeline[index].sounds.forEach(function (time) {
                                    const source = audioContext.createBufferSource();
                                    source.buffer = entry.audio;
                                    source.connect(audioContext.destination);
                                    if (destination) { source.connect(destination); }
                                    sources.push(source);
                                    source.start(startingAt + time);
                                });
                            });
                            frame = requestAnimationFrame(tick);
                        } catch (error) { reject(error); }
                    };
                    watchdog = setTimeout(function () { reject(fail('recording_timeout')); }, (snapshot.duration + 20) * 1000);
                    if (record) {
                        try {
                            destination = audioContext.createMediaStreamDestination();
                            capture = canvas.captureStream(30);
                            stream = new MediaStream(capture.getVideoTracks().concat(destination.stream.getAudioTracks()));
                            recorder = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 6000000, audioBitsPerSecond: 192000 });
                            recorder.ondataavailable = function (event) {
                                if (!event.data || !event.data.size || signal.aborted || cleaned) { return; }
                                capturedBytes += event.data.size;
                                if (capturedBytes > maximumOutputBytes) { reject(fail('memory_limit')); return; }
                                chunks.push(event.data);
                            };
                            recorder.onerror = function () { reject(fail('recording_failed')); };
                            recorder.onstop = function () {
                                if (signal.aborted || cleaned) { return; }
                                if (!stopRequested) { reject(fail('recording_failed')); return; }
                                const actualMime = recorder.mimeType || (chunks[0] && chunks[0].type) || mime;
                                const blob = new Blob(chunks, { type: actualMime });
                                if (!blob.size) { reject(fail('recording_empty')); return; }
                                resolve({ blob: blob, mimeType: actualMime, extension: /^video\/mp4\b/i.test(actualMime) ? 'mp4' : 'webm', duration: snapshot.duration });
                            };
                            recorder.onstart = function () { clearTimeout(startTimeout); };
                            startTimeout = setTimeout(function () { reject(fail('recording_timeout')); }, 10000);
                            recorder.start(1000);
                            // Some encoders emit "start" only after receiving their first frame.
                            // Start drawing/audio now; waiting for that event would deadlock.
                            start();
                        } catch (error) { reject(error.code && typeof error.code === 'string' ? error : fail('recording_failed')); }
                    } else {
                        start();
                    }
                });
                checkAbort(signal);
                return result;
            } finally {
                cleanup();
                operation.unlink();
                if (active === operation) { active = null; }
            }
        }

        function dispose() {
            if (disposed) { return; }
            disposed = true;
            cancel();
            releaseMedia(prepared && prepared.media);
            prepared = null;
            if (context && context.state !== 'closed') { context.close().catch(function () {}); }
            context = null;
        }

        return { prepare: prepare, draw: draw, play: play, cancel: cancel, dispose: dispose };
    }

    global.LLToolsCategoryVideoRenderer = { create: create };
})(window);
