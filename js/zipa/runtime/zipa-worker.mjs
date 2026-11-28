const version = new URL(import.meta.url).search;
const featureModule = import(`./zipa-features.mjs${version}`);
const modelAssets = import(`./zipa-model-assets.mjs${version}`);
const ortModule = import(`./vendor/ort.wasm.min.mjs${version}`);

let recognizer;
let pendingInitialization;
let busy = false;

class RequestError extends Error {
    constructor(code, message) {
        super(message);
        this.code = code;
    }
}

function progress(phase, loaded, total) {
    self.postMessage({type: 'progress', phase, loaded, total});
}

function readSymbols(tokens) {
    const source = new TextDecoder('utf-8', {fatal: true}).decode(tokens).trim();
    const entries = source.startsWith('{') ? Object.entries(JSON.parse(source)) : source.split(/\r?\n/u).map(line => {
        const pair = /^(\S+)\s+(\d+)$/u.exec(line.trim());
        if (!pair) throw new Error('The ZIPA alphabet is invalid.');
        return [pair[1], Number(pair[2])];
    });
    entries.sort((a, b) => a[1] - b[1]);
    const symbols = entries.map(([label]) => label);
    if (symbols[0] !== '<blk>' || symbols[3] !== '▁' || new Set(symbols).size !== symbols.length
        || entries.some(([, id], index) => id !== index)) {
        throw new Error('The ZIPA alphabet is invalid.');
    }
    return symbols;
}

async function initialize(savedOnly, manifest) {
    if (!pendingInitialization) {
        pendingInitialization = (async () => {
            const [features, {loadModelAssets}, ort] = await Promise.all([featureModule, modelAssets, ortModule]);
            const {model, tokens, wasm} = await loadModelAssets({
                savedOnly, manifest, onProgress: (loaded, total) => progress('model', loaded, total),
            });
            const symbols = readSymbols(tokens);
            progress('runtime', 0, null);
            ort.env.wasm.numThreads = 1;
            ort.env.wasm.wasmPaths = {mjs: new URL(`./vendor/ort-wasm-simd-threaded.mjs${version}`, import.meta.url)};
            ort.env.wasm.wasmBinary = wasm.buffer;
            try {
                const session = await ort.InferenceSession.create(model, {executionProviders: ['wasm']});
                recognizer = {session, symbols, features, Tensor: ort.Tensor};
            } finally {
                ort.env.wasm.wasmBinary = undefined;
            }
        })().catch(error => {
            pendingInitialization = undefined;
            throw error;
        });
    }
    await pendingInitialization;
}

function validatePcm(pcm, sampleRate) {
    if (!(pcm instanceof Float32Array) || !Number.isInteger(sampleRate) || sampleRate < 8000 || sampleRate > 192000) {
        throw new RequestError('invalid_audio', 'The recognizer needs a valid mono audio recording.');
    }
    const duration = pcm.length / sampleRate;
    if (duration > 8.001) throw new RequestError('audio_too_long', 'Keep the recording to eight seconds or less.');
    if (duration < 0.2) throw new RequestError('invalid_audio', 'The recording is too short.');
    let peak = 0;
    for (const sample of pcm) {
        if (!Number.isFinite(sample)) throw new RequestError('invalid_audio', 'The recording has invalid audio samples.');
        peak = Math.max(peak, Math.abs(sample));
    }
    if (peak < 0.001) throw new RequestError('empty_transcript', 'No speech was detected. Please try again.');
}

function greedyIpa(output, symbols) {
    const logProbs = output.log_probs;
    const vocabSize = logProbs.dims[2];
    const frames = Number(output.log_probs_len.data[0]);
    if (vocabSize !== symbols.length) throw new Error('The model and ZIPA alphabet do not match.');
    let previous = -1;
    let text = '';
    for (let frame = 0; frame < frames; frame++) {
        const offset = frame * vocabSize;
        let best = 0;
        for (let candidate = 1; candidate < vocabSize; candidate++) {
            if (logProbs.data[offset + candidate] > logProbs.data[offset + best]) best = candidate;
        }
        if (best >= 3 && best !== previous) text += best === 3 ? ' ' : symbols[best];
        previous = best;
    }
    return text.trim();
}

async function transcribe(pcm, sampleRate) {
    if (!recognizer) throw new RequestError('model_not_ready', 'The local speech model is still loading.');
    if (busy) throw new RequestError('recognizer_busy', 'Wait for the previous attempt to finish.');
    const started = performance.now();
    validatePcm(pcm, sampleRate);
    const {features: {FEATURE_DIM, SIGNAL_RATE, logFbank, resampleTo16k}, Tensor} = recognizer;
    const {frames, values} = logFbank(sampleRate === SIGNAL_RATE ? pcm : resampleTo16k(pcm, sampleRate));
    const inputs = {
        x: new Tensor('float32', values, [1, frames, FEATURE_DIM]),
        x_lens: new Tensor('int64', BigInt64Array.of(BigInt(frames)), [1]),
    };
    busy = true;
    let output;
    try {
        output = await recognizer.session.run(inputs);
        const text = greedyIpa(output, recognizer.symbols);
        if (!text) throw new RequestError('empty_transcript', 'No speech was detected. Please try again.');
        return {predicted_ipa: text, text, duration_ms: Math.round((performance.now() - started) * 10) / 10};
    } finally {
        for (const tensor of Object.values(inputs)) tensor.dispose();
        if (output) {
            for (const tensor of Object.values(output)) tensor.dispose();
        }
        busy = false;
    }
}

self.addEventListener('message', async ({data}) => {
    if (!data || (data.type !== 'init' && data.type !== 'transcribe')) return;
    try {
        if (data.type === 'init') {
            await initialize(data.savedOnly === true, data.manifest);
            self.postMessage({type: 'ready'});
        } else {
            const result = await transcribe(data.pcm, data.sampleRate);
            self.postMessage({type: 'result', id: data.id, ...result});
        }
    } catch (error) {
        const code = error.code || (data.type === 'init' ? 'model_load_failed' : 'inference_failed');
        self.postMessage({type: 'error', id: data.id, code, message: String(error.message || error)});
    }
});
