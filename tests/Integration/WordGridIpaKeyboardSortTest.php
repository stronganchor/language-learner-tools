<?php
declare(strict_types=1);

final class WordGridIpaKeyboardSortTest extends LL_Tools_TestCase
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

    public function test_secondary_text_symbol_sort_keeps_related_variants_adjacent(): void
    {
        $sorted = ll_tools_sort_secondary_text_symbols(['ɹ', 'qʰ', 'ʃ', 'r', 'q'], 'ipa');

        $this->assertSame(['q', 'qʰ', 'r', 'ɹ', 'ʃ'], $sorted);
    }

    public function test_wordset_ipa_special_char_rebuild_uses_rough_alphabetic_order(): void
    {
        $wordset_id = $this->createWordset();
        $word_id = $this->createWord($wordset_id, 'Keyboard Sort Word');
        $this->createRecording($word_id, 'ʒ qʰ ʃ ɹ');

        $symbols = ll_tools_word_grid_rebuild_wordset_ipa_special_chars($wordset_id);

        $this->assertSame(['qʰ', 'ɹ', 'ʃ', 'ʒ'], $symbols);
        $this->assertSame($symbols, get_term_meta($wordset_id, 'll_wordset_ipa_special_chars', true));
    }

    public function test_ipa_keyboard_symbols_collapse_modifier_combinations(): void
    {
        $wordset_id = $this->createWordset();
        $word_id = $this->createWord($wordset_id, 'Keyboard Modifier Word');
        $this->createRecording($word_id, 'qʰ dʲ tʷ aː ʃ ʒ');

        $stored_symbols = ll_tools_word_grid_rebuild_wordset_ipa_special_chars($wordset_id);

        $this->assertContains('qʰ', $stored_symbols);
        $this->assertContains('dʲ', $stored_symbols);
        $this->assertContains('tʷ', $stored_symbols);
        $this->assertContains('aː', $stored_symbols);

        $config = ll_tools_ipa_keyboard_get_transcription_config($wordset_id);

        $this->assertSame(['ʰ', 'ʲ', 'ʷ', 'ː', "\u{0325}", "\u{032A}", "\u{0306}", "\u{0361}"], array_values((array) ($config['modifier_chars'] ?? [])));
        $this->assertSame(['ʃ', 'ʒ'], array_values((array) ($config['keyboard_symbols'] ?? [])));
    }

    public function test_ipa_keyboard_groups_reviewed_symbols_by_type_and_frequency(): void
    {
        $wordset_id = $this->createWordset();
        $first_word_id = $this->createWord($wordset_id, 'Reviewed One');
        $this->createRecording($first_word_id, "ʃ ɛ t\u{0361}ʃ ʔ ɬ r\u{0325}");

        $second_word_id = $this->createWord($wordset_id, 'Reviewed Two');
        $this->createRecording($second_word_id, "ʃ ɛ d\u{0361}ʒ");

        $unreviewed_word_id = $this->createWord($wordset_id, 'Unreviewed');
        $unreviewed_recording_id = $this->createRecording($unreviewed_word_id, 'ʡ ʒ');
        ll_tools_ipa_keyboard_mark_recording_needs_auto_review($unreviewed_recording_id);

        $config = ll_tools_ipa_keyboard_get_transcription_config($wordset_id);
        $groups = [];
        $group_keys = [];
        foreach ((array) ($config['keyboard_groups'] ?? []) as $group) {
            $group_key = (string) ($group['key'] ?? '');
            $groups[$group_key] = array_values((array) ($group['symbols'] ?? []));
            $group_keys[] = $group_key;
        }

        $this->assertContains('ʔ', $groups['signs'] ?? []);
        $this->assertNotContains('ʡ', $groups['signs'] ?? []);
        $vowels_index = array_search('vowels', $group_keys, true);
        $affricates_index = array_search('affricates', $group_keys, true);
        $this->assertNotFalse($vowels_index);
        $this->assertNotFalse($affricates_index);
        $this->assertTrue($vowels_index < $affricates_index, 'Vowels should render before affricates.');
        $this->assertCount(2, $groups['affricates'] ?? []);
        $this->assertContains("t\u{0361}ʃ", $groups['affricates'] ?? []);
        $this->assertContains("d\u{0361}ʒ", $groups['affricates'] ?? []);
        $this->assertSame(['ɛ'], $groups['vowels'] ?? []);
        $this->assertSame(['ʃ'], $groups['consonants'] ?? []);
        $this->assertSame(['ɬ'], $groups['rare'] ?? []);
        $this->assertNotContains("r\u{0325}", array_values((array) ($config['keyboard_symbols'] ?? [])));
    }

    public function test_ipa_keyboard_groups_all_reviewed_tie_bar_tokens_before_compacting_diacritics(): void
    {
        $wordset_id = $this->createWordset();

        $first_word_id = $this->createWord($wordset_id, 'Reviewed Tie Bars One');
        $this->createRecording(
            $first_word_id,
            "c\u{0361}ç d\u{0361}ʒ t\u{032A}\u{0361}ʙ\u{0325} t\u{032A}\u{0361}\u{10784}"
        );

        $second_word_id = $this->createWord($wordset_id, 'Reviewed Tie Bars Two');
        $this->createRecording(
            $second_word_id,
            "t\u{0361}ʃ t\u{0361}ʙ\u{0325} d\u{032A}\u{0361}ʙ"
        );

        $config = ll_tools_ipa_keyboard_get_transcription_config($wordset_id);
        $groups = [];
        foreach ((array) ($config['keyboard_groups'] ?? []) as $group) {
            $groups[(string) ($group['key'] ?? '')] = array_values((array) ($group['symbols'] ?? []));
        }

        $expected_tie_bar_tokens = [
            "c\u{0361}ç",
            "d\u{0361}ʒ",
            "t\u{0361}ʃ",
            "t\u{032A}\u{0361}ʙ\u{0325}",
            "t\u{032A}\u{0361}\u{10784}",
            "t\u{0361}ʙ\u{0325}",
            "d\u{032A}\u{0361}ʙ",
        ];

        foreach ($expected_tie_bar_tokens as $symbol) {
            $this->assertContains($symbol, $groups['affricates'] ?? []);
            $this->assertNotContains($symbol, $groups['rare'] ?? []);
            $this->assertNotContains($symbol, $groups['consonants'] ?? []);
        }

        $details = (array) ($config['symbol_details'] ?? []);
        $this->assertSame('voiceless palatal affricate', (string) ($details["c\u{0361}ç"]['label'] ?? ''));
        $this->assertSame(
            'voiceless dental stop with voiceless bilabial trill release',
            (string) ($details["t\u{032A}\u{0361}ʙ\u{0325}"]['label'] ?? '')
        );
    }

    public function test_ipa_keyboard_skips_malformed_and_redundant_modified_tie_bar_shortcuts(): void
    {
        $wordset_id = $this->createWordset();

        $word_id = $this->createWord($wordset_id, 'Reviewed Modified Tie Bars');
        $this->createRecording(
            $word_id,
            "d\u{032A}\u{0361}b d\u{032A}\u{0361}b\u{02B2} c\u{0361}\u{02B0}\u{025B}"
        );

        $config = ll_tools_ipa_keyboard_get_transcription_config($wordset_id);
        $groups = [];
        foreach ((array) ($config['keyboard_groups'] ?? []) as $group) {
            $groups[(string) ($group['key'] ?? '')] = array_values((array) ($group['symbols'] ?? []));
        }

        $this->assertContains("d\u{032A}\u{0361}b", $groups['affricates'] ?? []);
        $this->assertNotContains("d\u{032A}\u{0361}b\u{02B2}", $groups['affricates'] ?? []);
        $this->assertNotContains("c\u{0361}\u{02B0}\u{025B}", $groups['affricates'] ?? []);
        $this->assertNotContains("d\u{032A}\u{0361}b\u{02B2}", array_values((array) ($config['keyboard_symbols'] ?? [])));
        $this->assertNotContains("c\u{0361}\u{02B0}\u{025B}", array_values((array) ($config['keyboard_symbols'] ?? [])));
    }

    private function createWordset(): int
    {
        $term = wp_insert_term('IPA Keyboard Sort ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));

        return (int) ($term['term_id'] ?? 0);
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

    private function createRecording(int $word_id, string $recording_ipa): int
    {
        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'IPA Keyboard Sort Recording ' . wp_generate_password(6, false, false),
        ]);

        update_post_meta($recording_id, 'recording_ipa', $recording_ipa);

        return $recording_id;
    }
}
