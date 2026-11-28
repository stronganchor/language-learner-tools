/** Decode a complete browser recording or local audio file for the ZIPA worker. */
export async function decodeRecording(blob) {
    if (!(blob instanceof Blob) || !blob.size) {
        throw new Error('Choose or record an audio clip first.');
    }
    if (blob.size > 4 * 1024 * 1024) {
        throw new Error('Choose an audio clip smaller than 4 MB.');
    }
    const OfflineContext = globalThis.OfflineAudioContext || globalThis.webkitOfflineAudioContext;
    const AudioContext = globalThis.AudioContext || globalThis.webkitAudioContext;
    let context;
    if (OfflineContext) {
        try {
            context = new OfflineContext(1, 1, 16000);
        } catch {
            // An older engine may reject 16 kHz offline decoding. The worker can resample.
        }
    }
    if (!context && AudioContext) {
        context = new AudioContext();
    }
    if (!context) throw new Error('This browser does not support local audio decoding.');
    let decoded;
    try {
        decoded = await context.decodeAudioData(await blob.arrayBuffer());
    } catch {
        throw new Error('This browser could not decode that recording.');
    } finally {
        if (typeof context.close === 'function') {
            await context.close().catch(() => {});
        }
    }
    // The recorder's stop timer can land after the eight-second inference limit.
    const pcm = decoded.getChannelData(0).slice(0, decoded.sampleRate * 8);
    return {pcm, sampleRate: decoded.sampleRate};
}
