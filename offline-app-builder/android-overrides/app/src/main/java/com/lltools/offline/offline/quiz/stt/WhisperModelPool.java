package com.lltools.offline.offline.quiz.stt;

import android.content.res.AssetManager;

import java.io.IOException;
import java.util.HashMap;
import java.util.Map;

final class WhisperModelPool {
    private static final WhisperModelPool INSTANCE = new WhisperModelPool();

    private final Map<String, WhisperModelSession> sessions = new HashMap<>();

    private WhisperModelPool() {
    }

    static WhisperModelPool getInstance() {
        return INSTANCE;
    }

    synchronized WhisperModelSession getOrCreate(AssetManager assetManager, EmbeddedSttModelSpec spec) throws IOException {
        String cacheKey = spec == null ? "" : spec.cacheKey();
        if (cacheKey.isEmpty()) {
            throw new IOException("Missing embedded STT model cache key.");
        }

        WhisperModelSession session = sessions.get(cacheKey);
        if (session != null) {
            return session;
        }

        session = new WhisperModelSession(assetManager, spec.assetModelPath);
        sessions.put(cacheKey, session);
        return session;
    }
}
