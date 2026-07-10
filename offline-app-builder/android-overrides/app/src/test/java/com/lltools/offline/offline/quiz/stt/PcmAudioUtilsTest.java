package com.lltools.offline.offline.quiz.stt;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.fail;

import java.util.Arrays;

import org.junit.Test;

public class PcmAudioUtilsTest {
    @Test
    public void calculatesFifteenSecondPcmBudgets() {
        assertEquals(480000, PcmAudioUtils.maxPcm16BytesForDuration(16000, 1));
        assertEquals(2880000, PcmAudioUtils.maxPcm16BytesForDuration(48000, 2));
        assertEquals(3, PcmAudioUtils.estimatePcm16DecodedBytes("AAAA"));
        assertEquals(1, PcmAudioUtils.estimatePcm16DecodedBytes("YQ==\n"));
    }

    @Test
    public void rejectsOversizedBase64BeforeDecode() {
        int maxBytes = PcmAudioUtils.maxPcm16BytesForDuration(16000, 1);
        char[] oversizedCharacters = new char[(((maxBytes + 2) / 3) * 4) + 4];
        Arrays.fill(oversizedCharacters, 'A');

        try {
            PcmAudioUtils.requirePcm16PayloadWithinLimit(new String(oversizedCharacters), maxBytes);
            fail("Expected oversized PCM payload rejection.");
        } catch (IllegalArgumentException expected) {
            assertEquals("PCM audio exceeds the 15-second limit.", expected.getMessage());
        }
    }

    @Test
    public void rejectsOverDurationSampleArrays() {
        PcmAudioUtils.requireSampleCountWithinLimit(PcmAudioUtils.MAX_TRANSCRIPTION_SAMPLES);

        try {
            PcmAudioUtils.requireSampleCountWithinLimit(PcmAudioUtils.MAX_TRANSCRIPTION_SAMPLES + 1);
            fail("Expected oversized sample-count rejection.");
        } catch (IllegalArgumentException expected) {
            assertEquals("PCM audio exceeds the 15-second limit.", expected.getMessage());
        }
    }
}
