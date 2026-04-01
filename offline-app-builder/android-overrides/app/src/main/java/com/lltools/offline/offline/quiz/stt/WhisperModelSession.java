package com.lltools.offline.offline.quiz.stt;

import android.content.res.AssetManager;

import java.io.IOException;
import java.util.Locale;

final class WhisperModelSession {
    private final String assetModelPath;
    private long contextPtr;

    WhisperModelSession(AssetManager assetManager, String assetModelPath) throws IOException {
        this.assetModelPath = assetModelPath == null ? "" : assetModelPath;
        this.contextPtr = WhisperNative.initContextFromAsset(assetManager, this.assetModelPath);
        if (this.contextPtr == 0L) {
            throw new IOException("Unable to load Whisper model asset: " + this.assetModelPath);
        }
    }

    synchronized String transcribe(float[] audioData, String language, boolean translate) throws IOException {
        if (contextPtr == 0L) {
            throw new IOException("The Whisper context is not available.");
        }

        int availableProcessors = Math.max(1, Runtime.getRuntime().availableProcessors());
        int threadCount = Math.max(2, Math.min(4, availableProcessors));
        int result = WhisperNative.fullTranscribe(
            contextPtr,
            threadCount,
            audioData == null ? new float[0] : audioData,
            language == null ? "auto" : language,
            translate,
            true,
            true
        );

        if (result != 0) {
            throw new IOException(String.format(Locale.US, "Whisper inference failed with code %d.", result));
        }

        int segmentCount = Math.max(0, WhisperNative.getTextSegmentCount(contextPtr));
        StringBuilder builder = new StringBuilder();
        for (int index = 0; index < segmentCount; index++) {
            String segment = WhisperNative.getTextSegment(contextPtr, index);
            if (segment == null || segment.trim().isEmpty()) {
                continue;
            }
            if (builder.length() > 0) {
                builder.append(' ');
            }
            builder.append(segment.trim());
        }

        return builder.toString().replaceAll("\\s+", " ").trim();
    }

    synchronized void close() {
        if (contextPtr != 0L) {
            WhisperNative.freeContext(contextPtr);
            contextPtr = 0L;
        }
    }

    String getAssetModelPath() {
        return assetModelPath;
    }
}
