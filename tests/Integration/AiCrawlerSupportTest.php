<?php
declare(strict_types=1);

final class AiCrawlerSupportTest extends LL_Tools_TestCase
{
    /** @var array<int,string> */
    private array $created_terms = [];

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        foreach (get_posts([
            'post_type' => ['ll_dictionary_entry', 'll_vocab_lesson', 'll_content_lesson'],
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => false,
        ]) as $post_id) {
            wp_delete_post((int) $post_id, true);
        }

        foreach ($this->created_terms as $term_id => $taxonomy) {
            wp_delete_term((int) $term_id, $taxonomy);
        }
        $this->created_terms = [];

        parent::tearDown();
    }

    public function test_llms_txt_lists_public_markdown_exports(): void
    {
        $content = ll_tools_ai_crawler_build_llms_txt();

        $this->assertStringStartsWith('# ', $content);
        $this->assertStringContainsString('/ll-tools/index.md', $content);
        $this->assertStringContainsString('/ll-tools/dictionary.md', $content);
        $this->assertStringContainsString('/ll-tools/wordsets.md', $content);
        $this->assertStringContainsString('/ll-tools/content-lessons.md', $content);
        $this->assertStringContainsString('Admin screens', $content);
    }

    public function test_dictionary_markdown_excludes_private_wordset_entries_for_anonymous_agents(): void
    {
        $public_wordset_id = $this->createWordset('AI Public Dictionary Wordset', 'ai-public-dictionary-wordset');
        $private_wordset_id = $this->createWordset('AI Private Dictionary Wordset', 'ai-private-dictionary-wordset', true);

        $public_entry_id = wp_insert_post([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => 'zewq',
            'post_content' => 'taste',
        ], true);
        $this->assertIsInt($public_entry_id);
        update_post_meta((int) $public_entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, (string) $public_wordset_id);
        update_post_meta((int) $public_entry_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY, [
            [
                'definition' => 'taste; flavor',
                'translations' => ['en' => 'taste; flavor'],
                'entry_type' => 'noun',
                'source_dictionary' => 'Test Dictionary',
                'dialects' => ['Palu'],
            ],
        ]);

        $private_entry_id = wp_insert_post([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => 'secret-headword',
            'post_content' => 'hidden definition',
        ], true);
        $this->assertIsInt($private_entry_id);
        update_post_meta((int) $private_entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, (string) $private_wordset_id);
        update_post_meta((int) $private_entry_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY, [
            [
                'definition' => 'hidden definition',
                'translations' => ['en' => 'hidden definition'],
                'entry_type' => 'noun',
            ],
        ]);

        wp_set_current_user(0);
        $content = ll_tools_ai_crawler_build_dictionary_markdown(['limit' => 20, 'sense_limit' => 4]);

        $this->assertStringContainsString('zewq', $content);
        $this->assertStringContainsString('taste; flavor', $content);
        $this->assertStringContainsString('Test Dictionary', $content);
        $this->assertStringContainsString('Palu', $content);
        $this->assertStringContainsString('ll_dictionary_entry=' . (string) $public_entry_id, $content);
        $this->assertStringNotContainsString('secret-headword', $content);
        $this->assertStringNotContainsString('hidden definition', $content);
    }

    public function test_wordset_and_content_exports_filter_private_surfaces(): void
    {
        $public_wordset_id = $this->createWordset('AI Public Wordset', 'ai-public-wordset');
        $private_wordset_id = $this->createWordset('AI Private Wordset', 'ai-private-wordset', true);
        $public_category_id = $this->createCategory('AI Public Category', 'ai-public-category');
        $private_category_id = $this->createCategory('AI Private Category', 'ai-private-category', true);

        $public_vocab_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Public AI Vocab Lesson',
        ]);
        update_post_meta((int) $public_vocab_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $public_wordset_id);
        update_post_meta((int) $public_vocab_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $public_category_id);

        $private_vocab_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Private AI Vocab Lesson',
        ]);
        update_post_meta((int) $private_vocab_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $public_wordset_id);
        update_post_meta((int) $private_vocab_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $private_category_id);

        $public_content_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'Public AI Content Lesson',
            'post_excerpt' => 'A public listening lesson.',
        ]);
        update_post_meta((int) $public_content_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, (string) $public_wordset_id);
        update_post_meta((int) $public_content_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, [(string) $public_category_id, (string) $private_category_id]);
        update_post_meta((int) $public_content_id, LL_TOOLS_CONTENT_LESSON_CUES_META, [
            ['start_ms' => 1000, 'end_ms' => 2500, 'text' => 'First public cue.'],
            ['start_ms' => 3000, 'end_ms' => 4500, 'text' => 'Second public cue.'],
        ]);

        $private_content_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'Private AI Content Lesson',
        ]);
        update_post_meta((int) $private_content_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, (string) $private_wordset_id);

        wp_set_current_user(0);
        $wordsets = ll_tools_ai_crawler_build_wordsets_markdown(['wordset_limit' => 20, 'lesson_limit' => 20]);
        $content = ll_tools_ai_crawler_build_content_lessons_markdown(['lesson_limit' => 20, 'cue_limit' => 2]);

        $this->assertStringContainsString('AI Public Wordset', $wordsets);
        $this->assertStringNotContainsString('AI Private Wordset', $wordsets);
        $this->assertStringContainsString('Public AI Vocab Lesson', $wordsets);
        $this->assertStringNotContainsString('Private AI Vocab Lesson', $wordsets);

        $this->assertStringContainsString('Public AI Content Lesson', $content);
        $this->assertStringContainsString('A public listening lesson.', $content);
        $this->assertStringContainsString('[0:01] First public cue.', $content);
        $this->assertStringContainsString('AI Public Category', $content);
        $this->assertStringNotContainsString('Private AI Content Lesson', $content);
        $this->assertStringNotContainsString('AI Private Category', $content);
    }

    private function createWordset(string $name, string $slug, bool $private = false): int
    {
        $result = wp_insert_term($name, 'wordset', ['slug' => $slug]);
        $this->assertIsArray($result);
        $term_id = (int) ($result['term_id'] ?? 0);
        $this->assertGreaterThan(0, $term_id);
        if ($private) {
            update_term_meta($term_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        }
        $this->created_terms[$term_id] = 'wordset';

        return $term_id;
    }

    private function createCategory(string $name, string $slug, bool $private = false): int
    {
        $result = wp_insert_term($name, 'word-category', ['slug' => $slug]);
        $this->assertIsArray($result);
        $term_id = (int) ($result['term_id'] ?? 0);
        $this->assertGreaterThan(0, $term_id);
        if ($private) {
            update_term_meta($term_id, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
        }
        $this->created_terms[$term_id] = 'word-category';

        return $term_id;
    }
}
