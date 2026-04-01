package com.lltools.offline.offline.quiz.stt;

final class EmbeddedSttModelSpec {
    final String engine;
    final String assetModelPath;
    final String assetManifestPath;
    final String language;
    final String task;

    EmbeddedSttModelSpec(String engine, String assetModelPath, String assetManifestPath, String language, String task) {
        this.engine = engine == null ? "" : engine;
        this.assetModelPath = assetModelPath == null ? "" : assetModelPath;
        this.assetManifestPath = assetManifestPath == null ? "" : assetManifestPath;
        this.language = language == null || language.trim().isEmpty() ? "auto" : language.trim();
        this.task = task == null || task.trim().isEmpty() ? "transcribe" : task.trim();
    }

    boolean supportsAndroid() {
        return "whisper.cpp".equals(engine) && !assetModelPath.isEmpty();
    }

    boolean shouldTranslate() {
        return "translate".equalsIgnoreCase(task);
    }

    String cacheKey() {
        return engine + "::" + assetModelPath;
    }
}
