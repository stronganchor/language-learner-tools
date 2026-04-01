package com.lltools.offline.offline.quiz.stt;

import android.content.res.AssetManager;

final class WhisperNative {
    private static Throwable loadError;

    static {
        try {
            System.loadLibrary("lltools_whisper");
        } catch (Throwable error) {
            loadError = error;
        }
    }

    private WhisperNative() {
    }

    static boolean isAvailable() {
        return loadError == null;
    }

    static String getLoadErrorMessage() {
        return loadError == null ? "" : String.valueOf(loadError.getMessage());
    }

    static native long initContextFromAsset(AssetManager assetManager, String assetPath);

    static native void freeContext(long contextPtr);

    static native int fullTranscribe(
        long contextPtr,
        int numThreads,
        float[] audioData,
        String language,
        boolean translate,
        boolean noTimestamps,
        boolean singleSegment
    );

    static native int getTextSegmentCount(long contextPtr);

    static native String getTextSegment(long contextPtr, int index);

    static native String getSystemInfo();
}
