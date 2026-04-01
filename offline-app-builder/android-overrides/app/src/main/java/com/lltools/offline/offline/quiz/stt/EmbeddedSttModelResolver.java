package com.lltools.offline.offline.quiz.stt;

import android.content.res.AssetManager;
import android.util.Log;

import com.getcapacitor.JSObject;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.IOException;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

final class EmbeddedSttModelResolver {
    private static final String LOG_TAG = "LLToolsOfflineStt";

    private EmbeddedSttModelResolver() {
    }

    static EmbeddedSttModelSpec resolve(AssetManager assetManager, JSObject model) {
        JSObject source = model == null ? new JSObject() : model;
        String engine = normalizeEngine(
            firstNonBlank(
                source.optString("engine", ""),
                source.optString("runtime", ""),
                source.optString("backend", "")
            )
        );
        String language = firstNonBlank(source.optString("language", ""));
        String task = firstNonBlank(source.optString("task", ""));
        String assetModelPath = normalizeAssetPath(source.optString("androidAssetModelPath", ""));
        String assetManifestPath = normalizeAssetPath(source.optString("androidAssetManifestPath", ""));
        String assetBasePath = normalizeAssetPath(source.optString("androidAssetPath", ""));

        if (assetBasePath.isEmpty()) {
            assetBasePath = normalizeAssetPath(toAndroidAssetPath(source.optString("webPath", "")));
        }
        if (assetManifestPath.isEmpty() && "directory".equalsIgnoreCase(source.optString("entryType", "")) && !assetBasePath.isEmpty()) {
            assetManifestPath = joinAssetPath(assetBasePath, "manifest.json");
        }

        JSONObject manifest = readJsonAsset(assetManager, assetManifestPath);
        if (manifest != null) {
            engine = normalizeEngine(firstNonBlank(
                engine,
                manifest.optString("engine", ""),
                manifest.optString("runtime", ""),
                manifest.optString("backend", ""),
                manifest.optString("format", "")
            ));
            language = firstNonBlank(language, manifest.optString("language", ""));
            task = normalizeTask(firstNonBlank(task, manifest.optString("task", ""), manifest.optString("mode", "")));

            if (assetModelPath.isEmpty()) {
                String modelPath = normalizeRelativePath(firstNonBlank(
                    manifest.optString("modelPath", ""),
                    manifest.optString("model_path", ""),
                    manifest.optString("model", ""),
                    manifest.optString("modelFile", ""),
                    manifest.optString("model_file", ""),
                    manifest.optString("file", "")
                ));
                if (!modelPath.isEmpty()) {
                    assetModelPath = joinAssetPath(assetBasePath, modelPath);
                }
            }
        }

        if (assetModelPath.isEmpty() && "file".equalsIgnoreCase(source.optString("entryType", ""))) {
            assetModelPath = normalizeAssetPath(firstNonBlank(
                source.optString("androidAssetPath", ""),
                toAndroidAssetPath(source.optString("webPath", ""))
            ));
        }

        if (engine.isEmpty()) {
            engine = inferEngineFromPath(assetModelPath);
        }

        language = firstNonBlank(language, "auto");
        task = normalizeTask(firstNonBlank(task, "transcribe"));

        if (!assetModelPath.isEmpty() && !assetExists(assetManager, assetModelPath)) {
            Log.w(LOG_TAG, "Embedded STT model asset is missing: " + assetModelPath);
            assetModelPath = "";
        }

        return new EmbeddedSttModelSpec(engine, assetModelPath, assetManifestPath, language, task);
    }

    private static String normalizeEngine(String value) {
        String engine = String.valueOf(value == null ? "" : value).trim().toLowerCase(Locale.US);
        engine = engine.replace('_', '.').replace(' ', '.');
        if (engine.equals("whispercpp") || engine.equals("ggml") || engine.equals("gguf")) {
            return "whisper.cpp";
        }
        return engine;
    }

    private static String normalizeTask(String value) {
        String task = String.valueOf(value == null ? "" : value).trim().toLowerCase(Locale.US);
        return "translate".equals(task) ? "translate" : "transcribe";
    }

    private static String normalizeAssetPath(String value) {
        String path = String.valueOf(value == null ? "" : value).trim().replace('\\', '/');
        while (path.startsWith("./")) {
            path = path.substring(2);
        }
        while (path.startsWith("/")) {
            path = path.substring(1);
        }
        return path;
    }

    private static String normalizeRelativePath(String value) {
        String path = normalizeAssetPath(value);
        if (path.isEmpty()) {
            return "";
        }

        String[] rawSegments = path.split("/");
        List<String> segments = new ArrayList<>();
        for (String segment : rawSegments) {
            if (segment == null || segment.isEmpty() || ".".equals(segment) || "..".equals(segment)) {
                continue;
            }
            segments.add(segment);
        }
        return String.join("/", segments);
    }

    private static String joinAssetPath(String basePath, String relativePath) {
        String base = normalizeAssetPath(basePath);
        String relative = normalizeRelativePath(relativePath);
        if (base.isEmpty()) {
            return relative;
        }
        if (relative.isEmpty()) {
            return base;
        }
        return base + "/" + relative;
    }

    private static String toAndroidAssetPath(String webPath) {
        String normalized = normalizeAssetPath(webPath);
        if (normalized.isEmpty()) {
            return "";
        }
        return normalized.startsWith("public/") ? normalized : "public/" + normalized;
    }

    private static String inferEngineFromPath(String assetPath) {
        String normalized = normalizeAssetPath(assetPath).toLowerCase(Locale.US);
        if (normalized.endsWith(".bin") || normalized.endsWith(".gguf")) {
            return "whisper.cpp";
        }
        return "";
    }

    private static boolean assetExists(AssetManager assetManager, String assetPath) {
        if (assetManager == null || assetPath == null || assetPath.isEmpty()) {
            return false;
        }

        try (InputStream stream = assetManager.open(assetPath)) {
            return stream != null;
        } catch (IOException ignored) {
            return false;
        }
    }

    private static JSONObject readJsonAsset(AssetManager assetManager, String assetPath) {
        if (!assetExists(assetManager, assetPath)) {
            return null;
        }

        try (InputStream stream = assetManager.open(assetPath);
             InputStreamReader reader = new InputStreamReader(stream, StandardCharsets.UTF_8);
             BufferedReader buffered = new BufferedReader(reader)) {
            StringBuilder builder = new StringBuilder();
            String line;
            while ((line = buffered.readLine()) != null) {
                builder.append(line);
            }
            return new JSONObject(builder.toString());
        } catch (Exception error) {
            Log.w(LOG_TAG, "Unable to read embedded STT manifest: " + assetPath, error);
            return null;
        }
    }

    private static String firstNonBlank(String... values) {
        if (values == null) {
            return "";
        }

        for (String value : values) {
            if (value != null && !value.trim().isEmpty()) {
                return value.trim();
            }
        }
        return "";
    }
}
