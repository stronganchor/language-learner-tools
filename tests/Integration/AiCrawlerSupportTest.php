<?php
declare(strict_types=1);

final class AiCrawlerSupportTest extends LL_Tools_TestCase
{
    /** @var array<int,string> */
    private array $created_terms = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
            ll_tools_bump_dictionary_browser_cache_version();
        }
    }

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

        if (function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
            ll_tools_bump_dictionary_browser_cache_version();
        }

        parent::tearDown();
    }

    public function test_llms_txt_lists_public_markdown_exports(): void
    {
        $content = ll_tools_ai_crawler_build_llms_txt();

        $this->assertStringStartsWith('# ', $content);
        $this->assertStringContainsString('/ll-tools/index.md', $content);
        $this->assertStringContainsString('/ll-tools/index.jsonld', $content);
        $this->assertStringContainsString('/ll-tools/dictionary.md', $content);
        $this->assertStringContainsString('/ll-tools/wordsets.md', $content);
        $this->assertStringContainsString('/ll-tools/content-lessons.md', $content);
        $this->assertStringContainsString('Admin screens', $content);
    }

    public function test_discovery_links_advertise_llms_and_ai_index(): void
    {
        $links = ll_tools_ai_crawler_discovery_links();
        $this->assertCount(3, $links);
        $this->assertSame(home_url('/llms.txt'), $links[0]['href']);
        $this->assertSame('text/plain', $links[0]['type']);
        $this->assertSame(home_url('/ll-tools/index.md'), $links[1]['href']);
        $this->assertSame('text/markdown', $links[1]['type']);
        $this->assertSame(home_url('/ll-tools/index.jsonld'), $links[2]['href']);
        $this->assertSame('application/ld+json', $links[2]['type']);

        ob_start();
        ll_tools_ai_crawler_render_head_links();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('href="' . esc_url(home_url('/llms.txt')) . '"', $html);
        $this->assertStringContainsString('href="' . esc_url(home_url('/ll-tools/index.md')) . '"', $html);
        $this->assertStringContainsString('href="' . esc_url(home_url('/ll-tools/index.jsonld')) . '"', $html);
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

    public function test_dictionary_letter_markdown_uses_public_letter_scope(): void
    {
        $public_wordset_id = $this->createWordset('AI Letter Public Wordset', 'ai-letter-public-wordset');
        $private_wordset_id = $this->createWordset('AI Letter Private Wordset', 'ai-letter-private-wordset', true);

        $this->createDictionaryEntry('Ava', $public_wordset_id, 'water');
        $this->createDictionaryEntry('Bero', $public_wordset_id, 'come');
        $this->createDictionaryEntry('A-secret', $private_wordset_id, 'hidden A definition');
        $this->createDictionaryEntry('Z-secret', $private_wordset_id, 'hidden Z definition');
        if (function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
            ll_tools_bump_dictionary_browser_cache_version();
        }

        wp_set_current_user(0);
        $content = ll_tools_ai_crawler_build_dictionary_letter_markdown('a', ['limit' => 20, 'sense_limit' => 4]);
        $letters = ll_tools_ai_crawler_get_dictionary_letters(20);

        $this->assertStringContainsString('# Public Dictionary: A', $content);
        $this->assertStringContainsString('Ava', $content);
        $this->assertStringContainsString('water', $content);
        $this->assertStringContainsString('/ll-tools/dictionary.md', $content);
        $this->assertStringNotContainsString('Bero', $content);
        $this->assertStringNotContainsString('A-secret', $content);
        $this->assertStringNotContainsString('hidden A definition', $content);
        $this->assertContains('A', $letters);
        $this->assertContains('B', $letters);
        $this->assertNotContains('Z', $letters);
    }

    public function test_jsonld_index_exposes_schema_graph_and_dictionary_chunks(): void
    {
        $public_wordset_id = $this->createWordset('AI JSON Public Wordset', 'ai-json-public-wordset');
        $private_wordset_id = $this->createWordset('AI JSON Private Wordset', 'ai-json-private-wordset', true);

        $this->createDictionaryEntry('Ava', $public_wordset_id, 'water');
        $this->createDictionaryEntry('Z-secret', $private_wordset_id, 'hidden Z definition');
        if (function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
            ll_tools_bump_dictionary_browser_cache_version();
        }

        wp_set_current_user(0);
        $json = ll_tools_ai_crawler_build_index_jsonld([
            'dictionary_entry_limit' => 5,
            'dictionary_letter_limit' => 10,
        ]);
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertSame('https://schema.org', $data['@context']);
        $graph = $data['@graph'] ?? [];
        $this->assertIsArray($graph);
        $types = array_map(static fn($node): string => is_array($node) ? (string) ($node['@type'] ?? '') : '', $graph);
        $this->assertContains('WebSite', $types);
        $this->assertContains('Dataset', $types);
        $this->assertContains('DefinedTermSet', $types);
        $this->assertContains('ItemList', $types);
        $this->assertStringContainsString('/ll-tools/dictionary/A.md', $json);
        $this->assertStringContainsString('DefinedTerm', $json);
        $this->assertStringContainsString('Ava', $json);
        $this->assertStringContainsString('water', $json);
        $this->assertStringNotContainsString('Z-secret', $json);
        $this->assertStringNotContainsString('hidden Z definition', $json);
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

    private function createDictionaryEntry(string $title, int $wordset_id, string $definition): int
    {
        $entry_id = wp_insert_post([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => $definition,
        ], true);
        $this->assertIsInt($entry_id);

        update_post_meta((int) $entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, (string) $wordset_id);
        update_post_meta((int) $entry_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY, [
            [
                'definition' => $definition,
                'translations' => ['en' => $definition],
                'entry_type' => 'noun',
                'source_dictionary' => 'Test Dictionary',
            ],
        ]);

        return (int) $entry_id;
    }
}
