package com.lltools.offline.offline.quiz.stt;

import android.util.Base64;

import java.nio.ByteBuffer;
import java.nio.ByteOrder;

final class PcmAudioUtils {
    private PcmAudioUtils() {
    }

    static float[] decodePcm16Base64(String encoded, int channels) {
        byte[] data = Base64.decode(encoded == null ? "" : encoded, Base64.DEFAULT);
        if (data.length < 2) {
            return new float[0];
        }

        int safeChannels = Math.max(1, channels);
        int sampleCount = data.length / 2;
        int frameCount = sampleCount / safeChannels;
        float[] output = new float[Math.max(frameCount, 0)];
        ByteBuffer buffer = ByteBuffer.wrap(data).order(ByteOrder.LITTLE_ENDIAN);

        for (int frameIndex = 0; frameIndex < frameCount; frameIndex++) {
            float sum = 0f;
            for (int channelIndex = 0; channelIndex < safeChannels; channelIndex++) {
                short sample = buffer.getShort();
                sum += (float) sample / 32768f;
            }
            output[frameIndex] = sum / safeChannels;
        }

        return output;
    }

    static float[] resampleLinear(float[] input, int sourceRate, int targetRate) {
        if (input == null || input.length == 0) {
            return new float[0];
        }
        if (sourceRate <= 0 || targetRate <= 0 || sourceRate == targetRate) {
            return input;
        }

        int targetLength = Math.max(1, Math.round((float) input.length * ((float) targetRate / (float) sourceRate)));
        float[] output = new float[targetLength];
        float ratio = (float) sourceRate / (float) targetRate;

        for (int index = 0; index < targetLength; index++) {
            float position = index * ratio;
            int leftIndex = (int) Math.floor(position);
            int rightIndex = Math.min(input.length - 1, leftIndex + 1);
            float blend = position - leftIndex;
            float leftSample = input[leftIndex];
            float rightSample = input[rightIndex];
            output[index] = (leftSample * (1f - blend)) + (rightSample * blend);
        }

        return output;
    }
}
