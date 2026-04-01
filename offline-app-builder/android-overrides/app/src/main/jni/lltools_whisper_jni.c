#include <jni.h>
#include <android/asset_manager.h>
#include <android/asset_manager_jni.h>
#include <android/log.h>
#include <stdbool.h>
#include <string.h>

#include "whisper.h"

#define UNUSED(x) (void)(x)
#define TAG "LLToolsWhisperJNI"
#define LOGW(...) __android_log_print(ANDROID_LOG_WARN, TAG, __VA_ARGS__)

static size_t asset_read(void * context, void * output, size_t read_size) {
    return AAsset_read((AAsset *) context, output, read_size);
}

static bool asset_is_eof(void * context) {
    return AAsset_getRemainingLength64((AAsset *) context) <= 0;
}

static void asset_close(void * context) {
    AAsset_close((AAsset *) context);
}

static struct whisper_context * lltools_whisper_init_from_asset(
    JNIEnv * env,
    jobject asset_manager_java,
    const char * asset_path
) {
    AAssetManager * asset_manager = AAssetManager_fromJava(env, asset_manager_java);
    if (!asset_manager) {
        LOGW("Failed to resolve Android asset manager");
        return NULL;
    }

    AAsset * asset = AAssetManager_open(asset_manager, asset_path, AASSET_MODE_STREAMING);
    if (!asset) {
        LOGW("Failed to open Whisper model asset: %s", asset_path);
        return NULL;
    }

    struct whisper_model_loader loader = {
        .context = asset,
        .read = &asset_read,
        .eof = &asset_is_eof,
        .close = &asset_close,
    };

    struct whisper_context_params context_params = whisper_context_default_params();
    return whisper_init_with_params(&loader, context_params);
}

JNIEXPORT jlong JNICALL
Java_com_lltools_offline_offline_quiz_stt_WhisperNative_initContextFromAsset(
    JNIEnv * env,
    jclass clazz,
    jobject asset_manager,
    jstring asset_path_str
) {
    UNUSED(clazz);
    if (!asset_manager || !asset_path_str) {
        return (jlong) 0;
    }

    const char * asset_path_chars = (*env)->GetStringUTFChars(env, asset_path_str, NULL);
    struct whisper_context * context = lltools_whisper_init_from_asset(env, asset_manager, asset_path_chars);
    (*env)->ReleaseStringUTFChars(env, asset_path_str, asset_path_chars);
    return (jlong) context;
}

JNIEXPORT void JNICALL
Java_com_lltools_offline_offline_quiz_stt_WhisperNative_freeContext(
    JNIEnv * env,
    jclass clazz,
    jlong context_ptr
) {
    UNUSED(env);
    UNUSED(clazz);
    struct whisper_context * context = (struct whisper_context *) context_ptr;
    if (context) {
        whisper_free(context);
    }
}

JNIEXPORT jint JNICALL
Java_com_lltools_offline_offline_quiz_stt_WhisperNative_fullTranscribe(
    JNIEnv * env,
    jclass clazz,
    jlong context_ptr,
    jint num_threads,
    jfloatArray audio_data,
    jstring language_str,
    jboolean translate,
    jboolean no_timestamps,
    jboolean single_segment
) {
    UNUSED(clazz);
    struct whisper_context * context = (struct whisper_context *) context_ptr;
    if (!context || !audio_data) {
        return -1;
    }

    jfloat * audio_data_arr = (*env)->GetFloatArrayElements(env, audio_data, NULL);
    jsize audio_data_length = (*env)->GetArrayLength(env, audio_data);
    const char * language_chars = NULL;

    struct whisper_full_params params = whisper_full_default_params(WHISPER_SAMPLING_GREEDY);
    params.print_realtime = false;
    params.print_progress = false;
    params.print_timestamps = false;
    params.print_special = false;
    params.translate = translate == JNI_TRUE;
    params.no_timestamps = no_timestamps == JNI_TRUE;
    params.token_timestamps = false;
    params.n_threads = num_threads > 0 ? num_threads : 1;
    params.offset_ms = 0;
    params.no_context = true;
    params.single_segment = single_segment == JNI_TRUE;
    params.detect_language = true;
    params.language = NULL;

    if (language_str != NULL) {
        language_chars = (*env)->GetStringUTFChars(env, language_str, NULL);
        if (language_chars != NULL && strlen(language_chars) > 0 && strcmp(language_chars, "auto") != 0) {
            params.language = language_chars;
            params.detect_language = false;
        }
    }

    whisper_reset_timings(context);
    int result = whisper_full(context, params, audio_data_arr, (int) audio_data_length);

    if (language_chars != NULL) {
        (*env)->ReleaseStringUTFChars(env, language_str, language_chars);
    }
    (*env)->ReleaseFloatArrayElements(env, audio_data, audio_data_arr, JNI_ABORT);

    return (jint) result;
}

JNIEXPORT jint JNICALL
Java_com_lltools_offline_offline_quiz_stt_WhisperNative_getTextSegmentCount(
    JNIEnv * env,
    jclass clazz,
    jlong context_ptr
) {
    UNUSED(env);
    UNUSED(clazz);
    struct whisper_context * context = (struct whisper_context *) context_ptr;
    return context ? whisper_full_n_segments(context) : 0;
}

JNIEXPORT jstring JNICALL
Java_com_lltools_offline_offline_quiz_stt_WhisperNative_getTextSegment(
    JNIEnv * env,
    jclass clazz,
    jlong context_ptr,
    jint index
) {
    UNUSED(clazz);
    struct whisper_context * context = (struct whisper_context *) context_ptr;
    const char * text = context ? whisper_full_get_segment_text(context, index) : "";
    return (*env)->NewStringUTF(env, text ? text : "");
}

JNIEXPORT jstring JNICALL
Java_com_lltools_offline_offline_quiz_stt_WhisperNative_getSystemInfo(
    JNIEnv * env,
    jclass clazz
) {
    UNUSED(clazz);
    const char * system_info = whisper_print_system_info();
    return (*env)->NewStringUTF(env, system_info ? system_info : "");
}
