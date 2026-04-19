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
