(function (window) {
    'use strict';

    const settings = window.llToolsZipaGames;
    const games = window.LLWordsetGames;
    if (!settings || !games || typeof games.init !== 'function') return;

    const site = new URL(window.location.href);
    const base = new URL(settings.assetBase, site);
    if (base.origin !== site.origin) return;
    const version = encodeURIComponent(settings.version);
    const MODES = ['speaking-practice', 'speaking-stack'];
    const DOWNLOAD_TIMEOUT_MS = 180000;
    const ATTEMPT_TIMEOUT_MS = 20000;
    const {text} = settings;

    function asset(path) {
        return new URL(path + '?v=' + version, base);
    }

    async function readResponse(response) {
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.data?.message || text.inferenceFailed);
        return result.data;
    }

    function hostedRequest(action, fields, blob) {
        const body = new FormData();
        body.set('action', action);
        body.set('wordset_id', settings.wordsetId);
        body.set('nonce', settings.nonce);
        for (const [name, value] of Object.entries(fields)) body.set(name, value);
        if (blob) {
            const extension = blob.type.includes('mp4') ? 'm4a' : blob.type.includes('ogg') ? 'ogg'
                : blob.type.includes('wav') ? 'wav' : 'webm';
            body.set('audio', blob, 'speaking-attempt.' + extension);
        }
        return fetch(settings.ajaxUrl, {method: 'POST', body, credentials: 'same-origin'}).then(readResponse);
    }

    function createHostedBridge() {
        async function transcribe(blob) {
            const result = await hostedRequest('ll_wordset_zipa_transcribe', {}, blob);
            if (!result.transcript) throw new Error(text.noSpeech);
            return result.transcript;
        }
        return {
            isSpeakingReady: () => true,
            transcribeSpeakingAttempt: transcribe,
            scoreSpeakingAttempt: (run, transcript) => hostedRequest('ll_wordset_zipa_score', {
                transcript, word_id: Number(run.prompt?.target?.id || 0),
            }),
            scoreSpeakingMatchAttempt: (_run, transcript, activeIds) => hostedRequest('ll_wordset_zipa_score', {
                transcript, word_ids: activeIds.join(','),
            }),
        };
    }

    function element(tag, content) {
        const node = document.createElement(tag);
        if (content) node.textContent = content;
        return node;
    }

    function announceStatus(container) {
        const message = element('span');
        message.className = 'screen-reader-text';
        message.dataset.llZipaDeviceStatus = '';
        message.setAttribute('role', 'status');
        container.prepend(message);

        return function update(state, label) {
            message.dataset.state = state;
            if (message.textContent !== label) message.textContent = label;
        };
    }

    function showDeckProblem(container) {
        const catalog = container.querySelector('[data-ll-wordset-games-catalog]');
        const problem = element('div');
        const message = element('p');
        message.className = 'll-wordset-game-card__status';
        const retry = element('button', text.retry);
        retry.className = 'll-wordset-games-notice__link';
        retry.type = 'button';
        problem.append(message);

        return function update(state, label, onRetry) {
            if (state !== 'deck-error' && state !== 'unsupported') {
                problem.remove();
                return;
            }
            message.textContent = label;
            retry.onclick = onRetry || null;
            if (onRetry) problem.append(retry);
            (catalog || container).append(problem);
        };
    }

    function isSupported() {
        return window.isSecureContext && typeof window.Worker === 'function' && !!window.WebAssembly
            && typeof window.MediaRecorder === 'function'
            && !!(window.OfflineAudioContext || window.webkitOfflineAudioContext || window.AudioContext || window.webkitAudioContext)
            && typeof window.navigator.mediaDevices?.getUserMedia === 'function';
    }

    function createRecognizer(update, manifest) {
        let connection = null;
        let failure = null;
        let nextId = 0;
        let audioDecoder;

        function close(current, error) {
            if (connection !== current) return;
            connection = null;
            current.worker.terminate();
            window.clearTimeout(current.initTimer);
            current.rejectReady(error);
            for (const request of current.pending.values()) {
                window.clearTimeout(request.timer);
                request.reject(error);
            }
            current.pending.clear();
        }

        function retryLoad() {
            failure = null;
            prepare().catch(() => {});
        }

        function fail(current) {
            if (connection !== current) return;
            failure = new Error(text.loadFailed);
            close(current, failure);
            update('model-error', text.loadFailed, 0, null, retryLoad);
        }

        function prepare(savedOnly = false) {
            if (connection) return connection.ready;
            if (failure) return Promise.reject(failure);
            let worker;
            try {
                worker = new Worker(asset('runtime/zipa-worker.mjs'), {
                    type: 'module', name: 'll-zipa-device',
                });
            } catch {
                failure = new Error(text.loadFailed);
                update('model-error', text.loadFailed, 0, null, retryLoad);
                return Promise.reject(failure);
            }

            let resolveReady;
            let rejectReady;
            const ready = new Promise((resolve, reject) => { resolveReady = resolve; rejectReady = reject; });
            const current = {worker, ready, rejectReady, pending: new Map()};
            connection = current;
            current.initTimer = window.setTimeout(() => fail(current), DOWNLOAD_TIMEOUT_MS);
            worker.addEventListener('error', event => {
                event.preventDefault();
                fail(current);
            });
            worker.addEventListener('message', ({data}) => {
                if (connection !== current || !data) return;
                if (data.type === 'progress') {
                    if (!savedOnly) {
                        const label = data.phase === 'runtime' ? text.initialize : text.download;
                        update('loading-model', label, data.loaded, data.total);
                    }
                } else if (data.type === 'ready') {
                    current.isReady = true;
                    window.clearTimeout(current.initTimer);
                    update('ready', '');
                    resolveReady(current);
                } else if (data.type === 'error' && data.id === undefined) {
                    if (savedOnly && data.code === 'cache_miss') {
                        close(current, new Error(text.loadFailed));
                        update('waiting', '');
                    } else {
                        fail(current);
                    }
                } else {
                    const job = current.pending.get(data.id);
                    if (!job) return;
                    current.pending.delete(data.id);
                    window.clearTimeout(job.timer);
                    update('ready', '');
                    if (data.type === 'result' && typeof data.predicted_ipa === 'string' && data.predicted_ipa.trim()) {
                        job.resolve(data.predicted_ipa);
                    } else {
                        job.reject(new Error(data.code === 'empty_transcript' ? text.noSpeech : text.inferenceFailed));
                    }
                }
            });
            if (savedOnly) update('preparing-model', text.initialize);
            else update('loading-model', text.download);
            try {
                worker.postMessage({type: 'init', savedOnly, manifest});
            } catch {
                fail(current);
            }
            return current.ready;
        }

        async function transcribe(blob) {
            audioDecoder ||= import(asset('runtime/zipa-audio.mjs').href).catch(() => {
                audioDecoder = null;
                throw new Error(text.decodeFailed);
            });
            const [current, decoder] = await Promise.all([prepare(), audioDecoder]);
            let recording;
            try {
                recording = await decoder.decodeRecording(blob);
            } catch {
                throw new Error(text.decodeFailed);
            }
            if (connection !== current || current.pending.size) throw new Error(text.inferenceFailed);

            update('processing', text.listening);
            return new Promise((resolve, reject) => {
                const id = ++nextId;
                const timer = window.setTimeout(() => fail(current), ATTEMPT_TIMEOUT_MS);
                current.pending.set(id, {resolve, reject, timer});
                const {pcm, sampleRate} = recording;
                try {
                    current.worker.postMessage({type: 'transcribe', id, pcm, sampleRate}, [pcm.buffer]);
                } catch {
                    window.clearTimeout(timer);
                    current.pending.delete(id);
                    update('ready', '');
                    reject(new Error(text.inferenceFailed));
                }
            });
        }

        window.addEventListener('pagehide', () => {
            if (connection) close(connection, new Error(text.inferenceFailed));
        });
        return {prepare, transcribe, isReady: () => !!connection?.isReady};
    }

    function createCardScorer(cards, matcher, spellTranscript) {
        const {normalizeIpa, scoreIpa, splitIpaBurst, splitSpellingBurst} = matcher;
        const byId = new Map(cards.map(card => [card.id, card]));
        const variants = Object.fromEntries(cards.map(card => [card.id, [card.recording_ipa, ...card.ipaVariants]]));
        const spellings = Object.fromEntries(cards.map(card => [card.id, card.title]));

        function resultPayload(result, heard, activeIds, expectedId) {
            const matched = result.matched && activeIds.includes(result.word_id);
            const card = byId.get(expectedId || (matched ? result.word_id : 0));
            const duplicateRepair = result.reason === 'duplicate_consonant_match' || result.reason === 'duplicate_vowel_match';
            return {
                matched,
                word_id: card?.id || null,
                score: matched ? result.score : 0,
                bucket: matched ? result.bucket : 'wrong',
                target_text: card?.recording_ipa || '',
                normalized_target_text: matched ? result.normalized_target_text : '',
                normalized_transcript_text: result.normalized_transcript_text,
                display_target_text: card?.title || '',
                display_transcript_text: matched && duplicateRepair ? card.title : heard,
                display_texts: card?.speaking_display_texts || {},
                best_correct_audio_url: card?.speaking_best_correct_audio_url || '',
                comparison_mode: 'recording_ipa',
                target_field: 'recording_ipa',
                target_label: 'IPA',
                provider: 'embedded_model',
            };
        }

        function score(transcript, activeIds, expectedId) {
            const stack = !expectedId;
            const heard = spellTranscript(transcript);
            const result = scoreIpa(transcript, variants, stack, heard, spellings);
            if (stack && result.reason === 'no_match') {
                const parts = splitIpaBurst(transcript, variants);
                const items = (parts || []).map((part) => {
                    const partHeard = spellTranscript(part);
                    const partResult = scoreIpa(part, variants, false, partHeard, spellings);
                    return resultPayload(partResult, partHeard, activeIds, 0);
                });
                if (parts?.length === 0 && !result.normalized_transcript_text.includes(' ')) {
                    for (const {word_id, repaired, display, ipa} of splitSpellingBurst(heard, spellings, transcript, variants)) {
                        if (word_id === null) {
                            items.push({matched: false, display_transcript_text: display ?? spellTranscript(ipa)});
                            continue;
                        }
                        const partResult = {
                            ...result,
                            word_id,
                            matched: true,
                            bucket: repaired ? 'close' : 'right',
                            score: repaired ? 85 : 100,
                            reason: repaired ? 'close_match' : ipa ? 'phonemic_match' : 'orthographic_match',
                            normalized_transcript_text: ipa ?? '',
                            normalized_target_text: ipa ?? normalizeIpa(variants[word_id][0]),
                        };
                        items.push(resultPayload(partResult, spellings[word_id], activeIds, 0));
                    }
                }
                const matches = new Map();
                for (const item of items) {
                    if (item.matched && !matches.has(item.word_id)) matches.set(item.word_id, item);
                }
                if (matches.size) {
                    const ordered = [...matches.values()];
                    return {
                        ...ordered[0],
                        matches: ordered,
                        display_transcript_text: items.map(item => item.display_transcript_text).filter(Boolean).join(' '),
                        normalized_transcript_text: result.normalized_transcript_text,
                    };
                }
            }
            return resultPayload(result, heard, activeIds, expectedId);
        }

        return score;
    }

    function createBrowserBridge(entries, matcher, createDisplay, profile, recognizer) {
        const practiceCards = entries['speaking-practice']?.words || [];
        const stackCards = entries['speaking-stack']?.words || [];
        const practice = createCardScorer(practiceCards, matcher, createDisplay(practiceCards, matcher, profile));
        const stack = createCardScorer(stackCards, matcher, createDisplay(stackCards, matcher, profile));
        return {
            isSpeakingReady: () => recognizer.isReady(),
            transcribeSpeakingAttempt: blob => recognizer.transcribe(blob),
            scoreSpeakingAttempt: (run, transcript) => {
                const expectedId = Number(run.prompt?.target?.id || 0);
                return practice(transcript, [expectedId], expectedId);
            },
            scoreSpeakingMatchAttempt: (_run, transcript, activeIds) => stack(transcript, activeIds.map(Number), 0),
        };
    }

    function installDownloadActions(container, recognizer, bytes) {
        const size = text.downloadSize.replace('%s', String(Math.round(bytes / 1000000)));
        const downloadLabel = text.downloadLabel.replace('%s', size);
        let retry;
        let activeDownload;
        const launchSelector = MODES.map(slug => `[data-game-slug="${slug}"] [data-ll-wordset-game-launch]`).join(',');
        const actions = [...container.querySelectorAll(launchSelector)].map(play => {
            const area = element('div');
            Object.assign(area.style, {
                display: 'inline-flex', flexDirection: 'column', justifyContent: 'center',
                minInlineSize: '120px', minBlockSize: '46px', gap: '0.4rem',
            });
            const download = element('button');
            download.type = 'button';
            download.className = 'll-wordset-game-card__launch';
            download.dataset.llZipaDeviceDownload = '';
            download.setAttribute('aria-label', downloadLabel);
            const icon = element('span', '↓');
            icon.setAttribute('aria-hidden', 'true');
            Object.assign(icon.style, {
                display: 'inline-block',
                lineHeight: '0.9',
                borderBottom: '1px solid currentColor',
                marginInlineEnd: '0.4em',
            });
            const caption = element('span', size);
            download.append(icon, caption);
            download.addEventListener('click', () => {
                activeDownload = download;
                if (retry) retry();
                else recognizer.prepare().catch(() => {});
            });
            const progress = element('progress');
            Object.assign(progress.style, {
                inlineSize: '100%', blockSize: '0.4rem',
                accentColor: 'var(--ll-wordset-game-theme-start)',
            });
            area.append(download);
            return {
                play, area, download, icon, caption, progress,
                originalDisplay: play.style.getPropertyValue('display'),
                originalPriority: play.style.getPropertyPriority('display'),
            };
        });

        return (state, label, loaded, total, onRetry) => {
            const loading = state === 'loading-model';
            const checking = state === 'checking-model';
            const preparing = state === 'preparing-model';
            retry = onRetry;
            if ((loading || retry) && !activeDownload) activeDownload = actions[0]?.download;
            for (const {play, area, download, icon, caption, progress, originalDisplay, originalPriority} of actions) {
                if (recognizer.isReady()) {
                    const hadFocus = document.activeElement === download;
                    area.remove();
                    progress.remove();
                    play.style.setProperty('display', originalDisplay, originalPriority);
                    if (hadFocus) play.focus({preventScroll: true});
                    continue;
                }
                play.style.setProperty('display', 'none', 'important');
                if (checking) {
                    area.remove();
                    progress.remove();
                    continue;
                }
                if (!area.isConnected) play.before(area);
                if (preparing) {
                    download.remove();
                } else {
                    if (!download.isConnected) area.prepend(download);
                    const canRetry = !!retry && download === activeDownload;
                    icon.hidden = canRetry;
                    const buttonCaption = canRetry ? text.retry : size;
                    if (caption.textContent !== buttonCaption) caption.textContent = buttonCaption;
                    download.setAttribute('aria-label', canRetry ? `${label} ${text.retry}` : downloadLabel);
                    download.disabled = loading || (!!retry && !canRetry);
                }
                const isDownloadingThisCard = loading && download === activeDownload;
                if (isDownloadingThisCard) {
                    download.setAttribute('aria-busy', 'true');
                } else {
                    download.removeAttribute('aria-busy');
                }
                if (preparing || isDownloadingThisCard) {
                    progress.setAttribute('aria-label', label);
                    if (Number.isFinite(total) && total > 0) {
                        progress.max = total;
                        progress.value = loaded;
                    } else {
                        progress.removeAttribute('value');
                    }
                    if (!progress.isConnected) area.append(progress);
                } else {
                    progress.remove();
                }
            }
        };
    }

    const initGames = games.init;
    games.init = function initBrowserSpeaking(root, cfg) {
        if (cfg?.view !== 'games' || Number(cfg.wordsetId) !== settings.wordsetId
            || typeof games.useBrowserSpeakingCatalog !== 'function') {
            return initGames.apply(this, arguments);
        }
        const container = root?.querySelector('[data-ll-wordset-games-root]');
        if (!container) return initGames.apply(this, arguments);
        if (container.hasAttribute('data-ll-zipa-device-root')) return games.__ctx;
        const ctx = initGames.call(this, root, {
            ...cfg,
            games: {...cfg.games, browserSpeakingOnly: true},
        });
        if (!ctx) return ctx;
        container.setAttribute('data-ll-zipa-device-root', '');
        const announce = announceStatus(container);
        const updateDeckProblem = showDeckProblem(container);
        let updateActions;
        let currentState;
        function update(state, label, loaded, total, onRetry) {
            currentState = state;
            announce(state, label);
            if (updateActions) updateActions(state, label, loaded, total, onRetry);
            else updateDeckProblem(state, label, onRetry);
        }
        if (!settings.hosted && !isSupported()) {
            update('unsupported', text.unsupported);
            return ctx;
        }

        const loadCards = async () => {
            update('loading-cards', text.loadingCards);
            try {
                const catalogUrl = new URL(settings.ajaxUrl, site);
                catalogUrl.searchParams.set('action', 'll_wordset_zipa_catalog');
                catalogUrl.searchParams.set('wordset_id', settings.wordsetId);
                if (settings.hosted) {
                    catalogUrl.searchParams.set('hosted', '1');
                    catalogUrl.searchParams.set('nonce', settings.nonce);
                }
                const config = await fetch(catalogUrl, {credentials: 'same-origin', cache: 'no-store'}).then(readResponse);
                if (!root.isConnected || window.LLWordsetGames.__ctx !== ctx) return;
                const entries = config.games;
                if (!entries || !Object.keys(entries).length) {
                    update('waiting', '');
                    return;
                }
                if (settings.hosted) {
                    if (games.useBrowserSpeakingCatalog(ctx, entries, createHostedBridge(), config.stackCapture)) {
                        update('ready', '');
                    }
                    return;
                }
                const manifestRequest = config.browserModelManifestUrl
                    ? fetch(new URL(config.browserModelManifestUrl, site), {credentials: 'omit', redirect: 'error'}).then(response => {
                        if (!response.ok) throw new Error(text.loadFailed);
                        return response.json();
                    }) : null;
                const [{createZipaMatcher}, {createOrthographicDisplay}, modelAssets, manifest] = await Promise.all([
                    import(asset('zipa-phonemic-matcher.mjs').href),
                    import(asset('zipa-orthographic-display.mjs').href),
                    import(asset('runtime/zipa-model-assets.mjs').href),
                    manifestRequest,
                ]);
                if (!root.isConnected || window.LLWordsetGames.__ctx !== ctx) return;
                const assets = modelAssets.getModelManifest(manifest);
                const totalBytes = Object.values(assets).reduce((total, item) => total + item.size, 0);
                const estimate = manifest?.downloadBytes;
                const downloadBytes = Number.isSafeInteger(estimate) && estimate >= 1000000 && estimate <= totalBytes
                    ? estimate : totalBytes;
                const recognizer = createRecognizer(update, manifest);
                const matcher = createZipaMatcher(config.matcher);
                const bridge = createBrowserBridge(entries, matcher, createOrthographicDisplay, config.matcher, recognizer);
                if (!games.useBrowserSpeakingCatalog(ctx, entries, bridge, config.stackCapture)) return;
                updateActions = installDownloadActions(container, recognizer, downloadBytes);
                async function restoreSavedModel() {
                    update('checking-model', '');
                    const saved = await modelAssets.hasSavedModel(manifest);
                    if (!root.isConnected || currentState !== 'checking-model') return;
                    if (saved) recognizer.prepare(true).catch(() => {});
                    else update('waiting', '');
                }
                window.addEventListener('pageshow', event => {
                    if (event.persisted && !recognizer.isReady() && currentState !== 'model-error') restoreSavedModel();
                });
                await restoreSavedModel();
            } catch {
                update('deck-error', text.missingCards, 0, null, loadCards);
            }
        };
        loadCards();
        return ctx;
    };
})(window);
