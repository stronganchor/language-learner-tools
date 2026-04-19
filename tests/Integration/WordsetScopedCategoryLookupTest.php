<?php
declare(strict_types=1);

final class WordsetScopedCategoryLookupTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $getBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        parent::tearDown();
    }

    public function test_unscoped_selector_labels_duplicate_isolated_categories_and_scoped_selector_filters_to_one_wordset(): void
    {
        $fixture = $this->createScopedCategoryFixture();

        $all_rows = ll_tools_get_word_category_selector_rows(0, [
            'post_types' => ['words'],
            'post_statuses' => ['publish'],
        ]);
        $all_labels = array_values(array_map(static function (array $row): string {
            return (string) ($row['label'] ?? '');
        }, $all_rows));

        $this->assertContains('Shared Trees - Scope One', $all_labels);
        $this->assertContains('Shared Trees - Scope Two', $all_labels);

        $scoped_rows = ll_tools_get_word_category_selector_rows((int) $fixture['wordset_one_id'], [
            'post_types' => ['words'],
            'post_statuses' => ['publish'],
        ]);
        $scoped_ids = array_values(array_map(static function (array $row): int {
            return (int) ($row['id'] ?? 0);
        }, $scoped_rows));
        $scoped_labels = array_values(array_map(static function (array $row): string {
            return (string) ($row['label'] ?? '');
        }, $scoped_rows));

        $this->assertContains((int) $fixture['isolated_one_id'], $scoped_ids);
        $this->assertNotContains((int) $fixture['isolated_two_id'], $scoped_ids);
        $this->assertContains('Shared Trees', $scoped_labels);
    }

    public function test_legacy_category_slug_resolves_to_selected_wordset_copy_across_scoped_lookup_paths(): void
    {
        $fixture = $this->createScopedCategoryFixture();

        $editor_payload = ll_tools_editor_hub_get_category_items_payload(
            (int) $fixture['wordset_one_id'],
            (string) $fixture['source_category_slug']
        );
        $editor_items = is_array($editor_payload['items'] ?? null) ? $editor_payload['items'] : [];

        $this->assertSame((string) $fixture['isolated_one_slug'], (string) ($editor_payload['selected_category'] ?? ''));
        $this->assertCount(1, $editor_items);
        $this->assertSame((int) $fixture['word_one_id'], (int) ($editor_items[0]['word_id'] ?? 0));

        $context = ll_tools_word_grid_resolve_context([
            'category' => (string) $fixture['source_category_slug'],
            'wordset' => (string) $fixture['wordset_one_slug'],
        ]);
        $context_term = $context['category_term'] ?? null;

        $this->assertInstanceOf(WP_Term::class, $context_term);
        $this->assertSame((int) $fixture['isolated_one_id'], (int) $context_term->term_id);
        $this->assertSame((string) $fixture['isolated_one_slug'], (string) ($context['sanitized_category'] ?? ''));

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Scoped Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (int) $fixture['wordset_one_id']);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (int) $fixture['isolated_one_id']);

        $resolved_lesson_id = ll_tools_find_vocab_lesson_post_id(
            (string) $fixture['wordset_one_slug'],
            (string) $fixture['source_category_slug']
        );

        $this->assertSame($lesson_id, $resolved_lesson_id);
    }

    public function test_bulk_translation_category_filter_scopes_to_selected_wordset(): void
    {
        $fixture = $this->createScopedCategoryFixture();
        $this->setCurrentUserWithViewCapability();

        $_GET = [
            'page' => 'll-bulk-translations',
            'wordset' => (string) $fixture['wordset_one_slug'],
        ];

        ob_start();
        ll_render_bulk_translations_page();
        $html = (string) ob_get_clean();

        $options = $this->extractSelectOptions($html, 'll-bulk-translations-category-filter');

        $this->assertArrayHasKey('0', $options);
        $this->assertArrayHasKey((string) $fixture['isolated_one_id'], $options);
        $this->assertArrayNotHasKey((string) $fixture['isolated_two_id'], $options);
        $this->assertSame('Shared Trees', (string) $options[(string) $fixture['isolated_one_id']]);
    }

    public function test_audio_image_matcher_category_filter_scopes_to_selected_wordset(): void
    {
        $fixture = $this->createScopedCategoryFixture();
        $this->setCurrentUserWithViewCapability();

        $_GET = [
            'page' => 'll-audio-image-matcher',
            'wordset_id' => (string) $fixture['wordset_one_id'],
        ];

        ob_start();
        ll_render_audio_image_matcher_page();
        $html = (string) ob_get_clean();

        $options = $this->extractSelectOptions($html, 'll-aim-category');

        $this->assertArrayHasKey('', $options);
        $this->assertArrayHasKey((string) $fixture['isolated_one_id'], $options);
        $this->assertArrayNotHasKey((string) $fixture['isolated_two_id'], $options);
        $this->assertSame('Shared Trees', (string) $options[(string) $fixture['isolated_one_id']]);
    }

    /**
     * @return array<string,int|string>
     */
    private function createScopedCategoryFixture(): array
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset_one_id = $this->ensureTerm('wordset', 'Scope One', 'scope-one');
        $wordset_two_id = $this->ensureTerm('wordset', 'Scope Two', 'scope-two');
        $source_category_id = $this->ensureTerm('word-category', 'Shared Trees', 'shared-trees');

        $isolated_one_id = (int) ll_tools_get_or_create_isolated_category_copy($source_category_id, $wordset_one_id);
        $isolated_two_id = (int) ll_tools_get_or_create_isolated_category_copy($source_category_id, $wordset_two_id);

        $word_one_id = $this->createWordInScope('Scope One Tree', $wordset_one_id, $isolated_one_id);
        $this->createWordInScope('Scope Two Tree', $wordset_two_id, $isolated_two_id);

        $isolated_one = get_term($isolated_one_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $isolated_one);

        return [
            'wordset_one_id' => $wordset_one_id,
            'wordset_one_slug' => 'scope-one',
            'isolated_one_id' => $isolated_one_id,
            'isolated_one_slug' => (string) $isolated_one->slug,
            'wordset_two_id' => $wordset_two_id,
            'isolated_two_id' => $isolated_two_id,
            'source_category_slug' => 'shared-trees',
            'word_one_id' => $word_one_id,
        ];
    }

    private function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = term_exists($slug, $taxonomy);
        if (is_array($existing) && !empty($existing['term_id'])) {
            return (int) $existing['term_id'];
        }
        if (is_int($existing) && $existing > 0) {
            return $existing;
        }

        $inserted = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertIsArray($inserted);

        return (int) $inserted['term_id'];
    }

    private function createWordInScope(string $title, int $wordset_id, int $category_id): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);

        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($word_id, [$category_id], 'word-category', false);

        return $word_id;
    }

    private function setCurrentUserWithViewCapability(): void
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);
    }

    /**
     * @return array<string,string>
     */
    private function extractSelectOptions(string $html, string $selectId): array
    {
        $pattern = '/<select[^>]*id="' . preg_quote($selectId, '/') . '"[^>]*>(.*?)<\/select>/si';
        $matches = [];
        $this->assertSame(1, preg_match($pattern, $html, $matches));

        $options = [];
        $option_matches = [];
        preg_match_all('/<option[^>]*value="([^"]*)"[^>]*>(.*?)<\/option>/si', (string) ($matches[1] ?? ''), $option_matches, PREG_SET_ORDER);
        foreach ($option_matches as $option_match) {
            $options[(string) ($option_match[1] ?? '')] = trim(html_entity_decode(wp_strip_all_tags((string) ($option_match[2] ?? '')), ENT_QUOTES, 'UTF-8'));
        }

        return $options;
    }
}
