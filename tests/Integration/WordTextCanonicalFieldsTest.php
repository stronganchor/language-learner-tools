<?php
declare(strict_types=1);

final class WordTextCanonicalFieldsTest extends LL_Tools_TestCase
{
    public function test_canonical_target_and_locale_translation_map_drive_display_text(): void
    {
        $wordset_id = $this->createTerm('wordset', 'Canonical Text Wordset', 'canonical-text-wordset');
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY, 'translation');
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, 'Turkish');

        $category_id = $this->createTerm('word-category', 'Canonical Text Category', 'canonical-text-category');
        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Legacy helper title',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'Legacy target text');
        update_post_meta($word_id, 'word_english_meaning', 'Legacy helper title');
        update_post_meta($word_id, LL_TOOLS_WORD_TARGET_TEXT_META_KEY, 'Zazaki canonical');
        update_post_meta($word_id, LL_TOOLS_WORD_TRANSLATIONS_META_KEY, [
            'tr' => 'Turkish helper',
            'en' => 'English helper',
        ]);

        $display = ll_tools_word_grid_resolve_display_text($word_id);
        $this->assertSame('Zazaki canonical', (string) ($display['word_text'] ?? ''));
        $this->assertSame('Turkish helper', (string) ($display['translation_text'] ?? ''));
        $this->assertTrue((bool) ($display['store_in_title'] ?? false));

        $display_map = ll_tools_get_word_display_text_map([$word_id]);
        $this->assertSame('Zazaki canonical', (string) ($display_map[$word_id]['word_text'] ?? ''));
        $this->assertSame('Turkish helper', (string) ($display_map[$word_id]['translation_text'] ?? ''));

        $english_parts = ll_tools_get_word_text_parts($word_id, 'en');
        $this->assertSame('Zazaki canonical', (string) ($english_parts['word_text'] ?? ''));
        $this->assertSame('English helper', (string) ($english_parts['translation_text'] ?? ''));

        $game_texts = ll_tools_wordset_games_resolve_unscramble_display_texts([
            'id' => $word_id,
            'wordset_ids' => [$wordset_id],
        ]);
        $this->assertSame('Zazaki canonical', (string) ($game_texts['answer_text'] ?? ''));
        $this->assertSame('Turkish helper', (string) ($game_texts['translation_text'] ?? ''));
    }

    public function test_canonical_writers_do_not_swap_target_and_default_translation(): void
    {
        $wordset_id = $this->createTerm('wordset', 'Canonical Writer Wordset', 'canonical-writer-wordset');
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY, 'translation');
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, 'Turkish');

        $category_id = $this->createTerm('word-category', 'Canonical Writer Category', 'canonical-writer-category');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Old helper title',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'Old target text');
        update_post_meta($word_id, 'word_english_meaning', 'Old helper title');

        $text_result = ll_tools_cli_update_word_text($word_id, 'New Zazaki target');
        $this->assertFalse(is_wp_error($text_result));
        ll_tools_cli_update_word_translation($word_id, 'Yeni Turkce helper');

        $this->assertSame('New Zazaki target', (string) get_post_meta($word_id, LL_TOOLS_WORD_TARGET_TEXT_META_KEY, true));
        $this->assertSame('New Zazaki target', get_the_title($word_id));
        $this->assertSame('Yeni Turkce helper', (string) get_post_meta($word_id, 'word_translation', true));

        $translations = get_post_meta($word_id, LL_TOOLS_WORD_TRANSLATIONS_META_KEY, true);
        $this->assertIsArray($translations);
        $this->assertSame('Yeni Turkce helper', (string) ($translations['tr'] ?? ''));

        $display = ll_tools_word_grid_resolve_display_text($word_id);
        $this->assertSame('New Zazaki target', (string) ($display['word_text'] ?? ''));
        $this->assertSame('Yeni Turkce helper', (string) ($display['translation_text'] ?? ''));
    }

    private function createTerm(string $taxonomy, string $name, string $slug): int
    {
        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertFalse(is_wp_error($created));
        $this->assertIsArray($created);

        return (int) $created['term_id'];
    }
}
