package com.lltools.offline.offline.quiz.stt;

import android.util.Log;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

@CapacitorPlugin(name = "LLToolsOfflineStt")
public class LLToolsOfflineSttPlugin extends Plugin {
    private static final String LOG_TAG = "LLToolsOfflineStt";
    private static final int TARGET_SAMPLE_RATE = 16000;

    private final ExecutorService executor = Executors.newSingleThreadExecutor();
    private final WhisperModelPool modelPool = WhisperModelPool.getInstance();

    @PluginMethod
    public void isEmbeddedSttAvailable(PluginCall call) {
        EmbeddedSttModelSpec spec = EmbeddedSttModelResolver.resolve(getContext().getAssets(), call.getData());
        JSObject response = new JSObject();
        boolean nativeAvailable = WhisperNative.isAvailable();
        response.put("available", nativeAvailable && spec.supportsAndroid());
        response.put("engine", spec.engine);
        response.put("task", spec.task);
        response.put("language", spec.language);
        response.put("modelPath", spec.assetModelPath);
        response.put("systemInfo", nativeAvailable ? WhisperNative.getSystemInfo() : "");
        response.put("loadError", nativeAvailable ? "" : WhisperNative.getLoadErrorMessage());
        call.resolve(response);
    }

    @PluginMethod
    public void transcribePcm(PluginCall call) {
        String encodedPcm = String.valueOf(call.getString("pcm16Base64", "")).trim();
        int sampleRate = call.getInt("sampleRate", TARGET_SAMPLE_RATE);
        int channels = call.getInt("channels", 1);
        JSObject model = call.getObject("model", new JSObject());

        if (encodedPcm.isEmpty()) {
            call.reject("Missing PCM audio data.");
            return;
        }
        if (!WhisperNative.isAvailable()) {
            call.reject("Offline STT is unavailable on this build. " + WhisperNative.getLoadErrorMessage());
            return;
        }

        executor.execute(() -> {
            try {
                EmbeddedSttModelSpec spec = EmbeddedSttModelResolver.resolve(getContext().getAssets(), model);
                if (!spec.supportsAndroid()) {
                    call.reject("The packaged STT model is not supported on Android.");
                    return;
                }

                float[] pcmData = PcmAudioUtils.decodePcm16Base64(encodedPcm, channels);
                if (sampleRate > 0 && sampleRate != TARGET_SAMPLE_RATE) {
                    pcmData = PcmAudioUtils.resampleLinear(pcmData, sampleRate, TARGET_SAMPLE_RATE);
                }

                WhisperModelSession session = modelPool.getOrCreate(getContext().getAssets(), spec);
                String transcript = session.transcribe(pcmData, spec.language, spec.shouldTranslate());
                JSObject response = new JSObject();
                response.put("text", transcript);
                response.put("engine", spec.engine);
                response.put("sampleRate", TARGET_SAMPLE_RATE);
                call.resolve(response);
            } catch (Throwable error) {
                Log.e(LOG_TAG, "Offline STT failed.", error);
                call.reject("Offline STT failed. " + error.getMessage());
            }
        });
    }
}
