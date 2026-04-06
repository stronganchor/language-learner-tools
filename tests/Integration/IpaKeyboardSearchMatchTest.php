<?php
declare(strict_types=1);

final class IpaKeyboardSearchMatchTest extends LL_Tools_TestCase
{
    public function test_transcription_search_matches_base_symbol_variants_by_default(): void
    {
        $payload = [
            'word_text' => '',
            'recording_text' => '',
            'recording_ipa' => 'd̪a',
        ];

        $this->assertTrue(
            ll_tools_ipa_keyboard_recording_matches_search($payload, 'd', 'transcription', 'ipa', false)
        );
    }

    public function test_exact_transcription_search_requires_matching_diacritics(): void
    {
        $payload = [
            'word_text' => '',
            'recording_text' => '',
            'recording_ipa' => 'd̪a',
        ];

        $this->assertFalse(
            ll_tools_ipa_keyboard_recording_matches_search($payload, 'd', 'transcription', 'ipa', true)
        );
        $this->assertTrue(
            ll_tools_ipa_keyboard_recording_matches_search($payload, 'd̪', 'transcription', 'ipa', true)
        );
    }

    public function test_exact_transcription_search_applies_to_advanced_patterns(): void
    {
        $payload = [
            'word_text' => '',
            'recording_text' => '',
            'recording_ipa' => 'd̪a',
        ];

        $this->assertTrue(
            ll_tools_ipa_keyboard_recording_matches_search($payload, 'd > a', 'transcription', 'ipa', false)
        );
        $this->assertFalse(
            ll_tools_ipa_keyboard_recording_matches_search($payload, 'd > a', 'transcription', 'ipa', true)
        );
        $this->assertTrue(
            ll_tools_ipa_keyboard_recording_matches_search($payload, 'd̪ > a', 'transcription', 'ipa', true)
        );
    }
}
