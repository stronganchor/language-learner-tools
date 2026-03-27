<?php
declare(strict_types=1);

final class WordGridIpaLanguageCasingTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (function_exists('ll_tools_register_words_post_type')) {
            ll_tools_register_words_post_type();
        }
        if (function_exists('ll_tools_register_word_audio_post_type')) {
            ll_tools_register_word_audio_post_type();
        }
        if (function_exists('ll_tools_register_wordset_taxonomy')) {
            ll_tools_register_wordset_taxonomy();
        }

        register_taxonomy_for_object_type('wordset', 'words');
    }

    /**
     * @dataProvider turkishStyleLanguageProvider
     */
    public function test_wordset_ipa_letter_map_rebuild_uses_dotless_i_for_turkish_style_languages(
        string $language_code,
        string $recording_text,
        string $recording_ipa
    ): void {
        $wordset_id = $this->createWordset($language_code);
        $word_id = $this->createWord($wordset_id, 'IPA Case Word ' . wp_generate_password(6, false, false));
        $this->createRecording($word_id, $recording_text, $recording_ipa);

        update_term_meta($wordset_id, 'll_wordset_ipa_letter_map', [
            'i' => [
                'ɨ' => 99,
            ],
        ]);
        update_term_meta($wordset_id, 'll_wordset_ipa_letter_map_case_version', 1);

        $map = ll_tools_word_grid_get_wordset_ipa_letter_map($wordset_id);

        $this->assertSame(1, (int) ($map['ı']['ɨ'] ?? 0));
        $this->assertFalse(isset($map['i']['ɨ']));
        $this->assertSame(
            LL_TOOLS_WORD_GRID_IPA_LETTER_MAP_CASE_VERSION,
            (int) get_term_meta($wordset_id, 'll_wordset_ipa_letter_map_case_version', true)
        );
    }

    public function test_ipa_keyboard_letter_normalizer_uses_dotless_i_for_zazaki(): void
    {
        $this->assertSame('ı', ll_tools_ipa_keyboard_normalize_letter_key('I', 'zza'));
        $this->assertSame('i', ll_tools_ipa_keyboard_normalize_letter_key('İ', 'zza'));
    }

    public function test_transcript_case_normalizer_preserves_dotless_i_for_zazaki_wordsets(): void
    {
        $wordset_id = $this->createWordset('zza');

        $this->assertSame('Incı', ll_tools_normalize_transcript_case('INCI', [$wordset_id]));
    }

    public static function turkishStyleLanguageProvider(): array
    {
        return [
            'turkish' => ['tr', 'Irmak', 'ɨɾmak'],
            'zazaki' => ['zza', 'Ina', 'ɨna'],
        ];
    }

    private function createWordset(string $language_code): int
    {
        $term = wp_insert_term('IPA Case Wordset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));

        $wordset_id = (int) ($term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $wordset_id);
        update_term_meta($wordset_id, 'll_language', $language_code);

        return $wordset_id;
    }

    private function createWord(int $wordset_id, string $title): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);

        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);

        return $word_id;
    }

    private function createRecording(int $word_id, string $recording_text, string $recording_ipa): int
    {
        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'IPA Case Recording ' . wp_generate_password(6, false, false),
        ]);

        update_post_meta($recording_id, 'recording_text', $recording_text);
        update_post_meta($recording_id, 'recording_ipa', $recording_ipa);

        return $recording_id;
    }
}
