// Original ZIPA-CR Small 300k export and complete tokenizer, published by
// AnySpeech under CC BY 4.0. See ../THIRD_PARTY_NOTICES.md for attribution.
const STOCK_SOURCE = 'https://huggingface.co/anyspeech/zipa-small-crctc-300k/resolve/2f458a4dd4102d49d2c7e54d9ccf69c660866256/';
const STOCK = {
    model: {
        url: STOCK_SOURCE + 'model.int8.onnx',
        size: 70677672,
        sha256: '8f0505173e4606b4afe041f19477b38d6a72a98a19863562749066dc496e86ae',
    },
    tokens: {
        url: STOCK_SOURCE + 'tokens.txt',
        size: 769,
        sha256: 'f8e042a0c9130532b22d03ec7cae2f75a23fbec70c450c31a8efb51787b2b8fe',
    },
};
const WASM = {
    url: 'https://cdn.jsdelivr.net/npm/onnxruntime-web@1.31.1/dist/ort-wasm-simd-threaded.wasm',
    size: 14848852,
    sha256: '4b884c203b0fa48d057be3332c65513ff85aa3c5bddd6946acc0fab7ecda001d',
};
const CACHE_NAME = 'll-tools-zipa-assets-1';
const HASH_HEADER = 'X-LL-ZIPA-SHA256';
const CHUNK_SIZE = 1024 * 1024;

function validateAsset(asset, maxBytes, mime) {
    if (!asset || typeof asset.url !== 'string' || !Number.isSafeInteger(asset.size)
        || asset.size < 1 || asset.size > maxBytes || !/^[a-f0-9]{64}$/i.test(asset.sha256)) {
        throw new Error('The ZIPA model file settings are invalid.');
    }
    const url = new URL(asset.url);
    if (url.protocol !== 'https:' || url.username || url.password || url.hash) {
        throw new Error('ZIPA model files require public HTTPS URLs.');
    }
    return {url: url.href, size: asset.size, sha256: asset.sha256.toLowerCase(), mime};
}

/** Resolve null to the stock model, or validate the public custom model and tokenizer. */
export function getModelManifest(manifest = null) {
    const assets = manifest === null ? STOCK : manifest;
    return {
        model: validateAsset(assets.model, 256 * 1024 * 1024, 'application/octet-stream'),
        tokens: validateAsset(assets.tokens, 64 * 1024, 'application/octet-stream'),
        wasm: {...WASM, mime: 'application/wasm'},
    };
}

function cacheKey(asset) {
    return new URL('./verified-assets/' + asset.sha256, import.meta.url).href;
}

async function openCache(create) {
    if (!globalThis.caches || (!create && !(await caches.has(CACHE_NAME)))) return null;
    return caches.open(CACHE_NAME);
}

function hasVerifiedMetadata(response, asset) {
    return response?.ok && response.headers.get(HASH_HEADER) === asset.sha256
        && response.headers.get('Content-Length') === String(asset.size);
}

// The earlier browser add-on cached verified files at their own same-origin
// URLs with a hash query parameter. Reuse those entries without altering its cache.
function previousCacheUrl(asset, model) {
    const url = asset.mime === 'application/wasm'
        ? new URL('ort-wasm-simd-threaded.wasm', model.url)
        : new URL(asset.url);
    if (url.origin !== globalThis.location?.origin || url.search) return null;
    url.searchParams.set('v', asset.sha256);
    return url.href;
}

async function previousCacheResponse(asset, model) {
    const url = previousCacheUrl(asset, model);
    if (url === null) return null;
    const response = await caches.match(url).catch(() => null);
    return hasVerifiedMetadata(response, asset) ? response : null;
}

function cacheMiss() {
    const error = new Error('The speech model is no longer saved on this device.');
    error.code = 'cache_miss';
    return error;
}

/** Check cached verification metadata without fetching model files or reading bodies. */
export async function hasSavedModel(manifest = null) {
    try {
        if (!globalThis.caches || !globalThis.crypto?.subtle) return false;
        const assets = getModelManifest(manifest);
        const cache = await openCache(false);
        const checks = await Promise.all(Object.values(assets).map(async asset => (
            hasVerifiedMetadata(await cache?.match(cacheKey(asset)), asset)
            || Boolean(await previousCacheResponse(asset, assets.model))
        )));
        return checks.every(Boolean);
    } catch {
        return false;
    }
}

function integrityError() {
    const error = new Error('An incomplete or invalid ZIPA file was received.');
    error.code = 'asset_integrity';
    return error;
}

async function readExact(response, asset, onProgress) {
    if (!response.body) {
        const bytes = new Uint8Array(await response.arrayBuffer());
        if (bytes.length !== asset.size) throw integrityError();
        return bytes;
    }

    const bytes = new Uint8Array(asset.size);
    const reader = response.body.getReader();
    let loaded = 0;
    let reported = 0;
    let complete = false;
    try {
        while (true) {
            const {done, value} = await reader.read();
            if (done) {
                complete = true;
                break;
            }
            if (loaded + value.length > bytes.length) throw integrityError();
            bytes.set(value, loaded);
            loaded += value.length;
            if (onProgress && loaded - reported >= CHUNK_SIZE) {
                onProgress(loaded);
                reported = loaded;
            }
        }
    } finally {
        if (!complete) await reader.cancel().catch(() => {});
        reader.releaseLock();
    }
    if (loaded !== bytes.length) throw integrityError();
    return bytes;
}

async function readVerified(response, asset, onProgress) {
    const bytes = await readExact(response, asset, onProgress);
    const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', bytes));
    const hex = Array.from(digest, value => value.toString(16).padStart(2, '0')).join('');
    if (hex !== asset.sha256) throw integrityError();
    return bytes;
}

async function saveVerified(cache, asset, bytes) {
    // Stream bounded pieces into Cache Storage without cloning the download.
    let offset = 0;
    const body = new ReadableStream({
        pull(controller) {
            const end = Math.min(offset + CHUNK_SIZE, bytes.length);
            controller.enqueue(bytes.subarray(offset, end));
            offset = end;
            if (offset === bytes.length) controller.close();
        },
    });
    await cache.put(cacheKey(asset), new Response(body, {
        headers: {
            'Content-Type': asset.mime,
            'Content-Length': String(asset.size),
            [HASH_HEADER]: asset.sha256,
        },
    }));
}

async function loadAsset(cache, asset, model, savedOnly, onProgress) {
    const key = cacheKey(asset);
    const saved = await cache?.match(key).catch(() => null);
    if (saved) {
        if (hasVerifiedMetadata(saved, asset)) {
            const bytes = await readVerified(saved, asset).catch(() => null);
            if (bytes) return bytes;
        }
        await cache.delete(key).catch(() => {});
    }
    if (globalThis.caches) {
        const previous = await previousCacheResponse(asset, model);
        if (previous) {
            const bytes = await readVerified(previous, asset).catch(() => null);
            if (bytes) return bytes;
        }
    }
    if (savedOnly) throw cacheMiss();

    const response = await fetch(asset.url, {
        cache: 'no-store',
        credentials: 'omit',
        mode: 'cors',
        referrerPolicy: 'no-referrer',
    });
    if (!response.ok) throw new Error('A ZIPA file could not be downloaded.');
    const bytes = await readVerified(response, asset, onProgress);
    if (cache) await saveVerified(cache, asset, bytes).catch(() => {});
    return bytes;
}

/** Download and verify the model, tokens and fixed WASM runtime after opt-in. */
export async function loadModelAssets({manifest = null, savedOnly = false, onProgress} = {}) {
    if (!globalThis.crypto?.subtle) {
        if (savedOnly) throw cacheMiss();
        throw new Error('ZIPA asset verification requires a secure browser context.');
    }
    const assets = getModelManifest(manifest);
    const cache = await openCache(!savedOnly).catch(() => null);
    const total = Object.values(assets).reduce((sum, asset) => sum + asset.size, 0);
    const files = {};
    let completedBytes = 0;
    onProgress?.(0, total);
    for (const [name, asset] of Object.entries(assets)) {
        files[name] = await loadAsset(cache, asset, assets.model, savedOnly,
            bytes => onProgress?.(completedBytes + bytes, total));
        completedBytes += asset.size;
        onProgress?.(completedBytes, total);
    }
    return files;
}
