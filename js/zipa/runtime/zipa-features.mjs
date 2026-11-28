// The tuned ZIPA model uses Kaldi-style 16 kHz, 80-bin log filterbanks.
export const SIGNAL_RATE = 16000;
export const FEATURE_DIM = 80;

const FRAME_SIZE = 400;
const FRAME_SHIFT = 160;
const FFT_SIZE = 512;
const LOG_FLOOR = 2 ** -23;
const f32 = Math.fround;

function gcd(a, b) {
    while (b !== 0) {
        [a, b] = [b, a % b];
    }
    return a;
}

function besselI0(x) {
    let term = 1;
    let sum = 1;
    const squaredHalf = x * x / 4;
    for (let i = 1; i <= 14; i++) {
        term *= squaredHalf / (i * i);
        sum += term;
    }
    return sum;
}

let previousFilter;
function resampleFilter(sampleRate) {
    if (previousFilter && previousFilter.sampleRate === sampleRate) {
        return previousFilter;
    }
    const divisor = gcd(SIGNAL_RATE, sampleRate);
    const up = SIGNAL_RATE / divisor;
    const down = sampleRate / divisor;
    const scale = Math.max(up, down);
    const half = 10 * scale;
    const taps = new Float64Array(2 * half + 1);
    const beta = 5;
    const normalizer = besselI0(beta);
    let sum = 0;
    for (let i = -half; i <= half; i++) {
        const angle = Math.PI * i / scale;
        const sinc = angle === 0 ? 1 : Math.sin(angle) / angle;
        const fraction = i / half;
        const window = besselI0(beta * Math.sqrt(1 - fraction * fraction)) / normalizer;
        const value = sinc * window;
        taps[i + half] = value;
        sum += value;
    }
    for (let i = 0; i < taps.length; i++) {
        taps[i] = f32(f32(taps[i] / sum) * up);
    }
    previousFilter = {sampleRate, up, down, half, taps};
    return previousFilter;
}

/** The same centered Kaiser-window FIR and constant padding as scipy.resample_poly. */
export function resampleTo16k(signal, sampleRate) {
    if (sampleRate === SIGNAL_RATE) {
        return signal;
    }
    const {up, down, half, taps} = resampleFilter(sampleRate);
    const output = new Float32Array(Math.ceil(signal.length * up / down));
    for (let i = 0; i < output.length; i++) {
        const center = i * down + half;
        const first = Math.max(0, Math.ceil((center - 2 * half) / up));
        const last = Math.min(signal.length - 1, Math.floor(center / up));
        let total = 0;
        for (let sample = first; sample <= last; sample++) {
            total = f32(total + f32(signal[sample] * taps[center - sample * up]));
        }
        output[i] = total;
    }
    return output;
}

const window = Float32Array.from({length: FRAME_SIZE}, (_, i) => (
    Math.pow(0.5 - 0.5 * Math.cos(2 * Math.PI * i / (FRAME_SIZE - 1)), 0.85)
));
const reverseIndex = Uint16Array.from({length: FFT_SIZE}, (_, index) => {
    let reversed = 0;
    for (let mask = FFT_SIZE >> 1; mask !== 0; mask >>= 1) {
        if (index & 1) reversed |= mask;
        index >>= 1;
    }
    return reversed;
});
const cosine = Float64Array.from({length: FFT_SIZE / 2}, (_, i) => Math.cos(-2 * Math.PI * i / FFT_SIZE));
const sine = Float64Array.from({length: FFT_SIZE / 2}, (_, i) => Math.sin(-2 * Math.PI * i / FFT_SIZE));

function mel(frequency) {
    return f32(1127 * f32(Math.log(f32(1 + f32(frequency / 700)))));
}

function makeMelFilters() {
    const start = mel(20);
    const step = f32(f32(mel(7600) - start) / (FEATURE_DIM + 1));
    const fftMels = Float32Array.from({length: FFT_SIZE / 2}, (_, i) => mel(i * SIGNAL_RATE / FFT_SIZE));
    return Array.from({length: FEATURE_DIM}, (_, bin) => {
        const left = f32(start + f32(bin * step));
        const center = f32(start + f32((bin + 1) * step));
        const right = f32(start + f32((bin + 2) * step));
        const first = fftMels.findIndex(value => value > left);
        const after = fftMels.findIndex(value => value >= right);
        const weights = fftMels.slice(first, after).map(value => (
            value <= center
                ? f32(value - left) / f32(center - left)
                : f32(right - value) / f32(right - center)
        ));
        return {first, weights};
    });
}
const melFilters = makeMelFilters();

function fft(real, imaginary) {
    for (let width = 2; width <= FFT_SIZE; width *= 2) {
        const half = width / 2;
        const stride = FFT_SIZE / width;
        for (let group = 0; group < FFT_SIZE; group += width) {
            for (let i = 0; i < half; i++) {
                const left = group + i;
                const right = left + half;
                const index = i * stride;
                const realPart = real[right] * cosine[index] - imaginary[right] * sine[index];
                const imaginaryPart = real[right] * sine[index] + imaginary[right] * cosine[index];
                real[right] = real[left] - realPart;
                imaginary[right] = imaginary[left] - imaginaryPart;
                real[left] += realPart;
                imaginary[left] += imaginaryPart;
            }
        }
    }
}

function reflectedIndex(index, length) {
    const folded = ((index % (2 * length)) + 2 * length) % (2 * length);
    return folded < length ? folded : 2 * length - 1 - folded;
}

/** First-channel Float32 PCM, already sampled at 16 kHz. */
export function logFbank(signal) {
    const frames = Math.floor((signal.length + FRAME_SHIFT / 2) / FRAME_SHIFT);
    const values = new Float32Array(frames * FEATURE_DIM);
    const buffer = new Float32Array(FRAME_SIZE);
    const real = new Float64Array(FFT_SIZE);
    const imaginary = new Float64Array(FFT_SIZE);
    const powers = new Float32Array(FFT_SIZE / 2);
    const preEmphasis = f32(0.97);
    for (let frame = 0; frame < frames; frame++) {
        const first = frame * FRAME_SHIFT + FRAME_SHIFT / 2 - FRAME_SIZE / 2;
        const interior = first >= 0 && first + FRAME_SIZE <= signal.length;
        let sum = 0;
        for (let i = 0; i < FRAME_SIZE; i++) {
            const sample = first + i;
            buffer[i] = signal[interior ? sample : reflectedIndex(sample, signal.length)];
            sum = f32(sum + buffer[i]);
        }
        const mean = f32(sum / FRAME_SIZE);
        for (let i = 0; i < FRAME_SIZE; i++) {
            buffer[i] = f32(buffer[i] - mean);
        }
        real.fill(0);
        imaginary.fill(0);
        for (let i = 0; i < FRAME_SIZE; i++) {
            const previous = buffer[Math.max(0, i - 1)];
            real[reverseIndex[i]] = f32(buffer[i] - f32(preEmphasis * previous)) * window[i];
        }
        fft(real, imaginary);
        for (let i = 0; i < powers.length; i++) {
            powers[i] = f32(f32(f32(real[i]) ** 2) + f32(f32(imaginary[i]) ** 2));
        }
        for (let band = 0; band < FEATURE_DIM; band++) {
            const {first: index, weights} = melFilters[band];
            let energy = 0;
            for (let weight = 0; weight < weights.length; weight++) {
                energy = f32(energy + f32(powers[index + weight] * weights[weight]));
            }
            values[frame * FEATURE_DIM + band] = Math.log(Math.max(LOG_FLOOR, energy));
        }
    }
    return {frames, values};
}
