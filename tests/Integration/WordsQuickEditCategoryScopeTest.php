<?php
declare(strict_types=1);

final class WordsQuickEditCategoryScopeTest extends LL_Tools_TestCase
{
    /** @var mixed */
    private $originalIsolationOption;

    /** @var mixed */
    private $pagenowBackup;

    /** @var mixed */
    private $typenowBackup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalIsolationOption = get_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, null);
        $this->pagenowBackup = $GLOBALS['pagenow'] ?? null;
        $this->typenowBackup = $GLOBALS['typenow'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalIsolationOption === null) {
            delete_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION);
        } else {
            update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, $this->originalIsolationOption, false);
        }

        if ($this->pagenowBackup === null) {
            unset($GLOBALS['pagenow']);
        } else {
            $GLOBALS['pagenow'] = $this->pagenowBackup;
        }

        if ($this->typenowBackup === null) {
            unset($GLOBALS['typenow']);
        } else {
            $GLOBALS['typenow'] = $this->typenowBackup;
        }

        parent::tearDown();
    }

    public function test_quick_edit_scope_config_prefers_selected_wordset_isolated_categories(): void
    {
        $fixture = $this->createScopedCategoryFixture();

        $config = ll_words_get_quick_edit_scope_config();
        $this->assertIsArray($config);

        $wordsets = $config['wordsets'] ?? null;
        $this->assertIsArray($wordsets);
        $this->assertSame(
            [(int) $fixture['wordset_one_id'], (int) $fixture['wordset_two_id']],
            array_values(array_map(static function (array $row): int {
                return (int) ($row['id'] ?? 0);
            }, $wordsets))
        );

        $category_ids_by_wordset = $config['categoryIdsByWordset'] ?? null;
        $this->assertIsArray($category_ids_by_wordset);

        $wordset_one_ids = $category_ids_by_wordset[(string) $fixture['wordset_one_id']] ?? null;
        $wordset_two_ids = $category_ids_by_wordset[(string) $fixture['wordset_two_id']] ?? null;

        $this->assertIsArray($wordset_one_ids);
        $this->assertIsArray($wordset_two_ids);
        $this->assertContains((int) $fixture['isolated_one_id'], $wordset_one_ids);
        $this->assertNotContains((int) $fixture['source_category_id'], $wordset_one_ids);
        $this->assertNotContains((int) $fixture['isolated_two_id'], $wordset_one_ids);
        $this->assertContains((int) $fixture['isolated_two_id'], $wordset_two_ids);
    }

    public function test_words_list_enqueue_localizes_quick_edit_scope_config(): void
    {
        $fixture = $this->createScopedCategoryFixture();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $GLOBALS['pagenow'] = 'edit.php';
        $GLOBALS['typenow'] = 'words';

        ll_words_enqueue_bulk_edit_script('edit.php');

        $localized = wp_scripts()->get_data('ll-words-bulk-edit', 'data');
        $this->assertIsString($localized);
        $this->assertStringContainsString('llWordsQuickEditData', $localized);

        $config = $this->extractLocalizedConfig($localized, 'llWordsQuickEditData');
        $category_ids_by_wordset = $config['categoryIdsByWordset'] ?? null;

        $this->assertIsArray($category_ids_by_wordset);
        $this->assertContains((int) $fixture['isolated_one_id'], $category_ids_by_wordset[(string) $fixture['wordset_one_id']] ?? []);
        $this->assertNotContains((int) $fixture['isolated_two_id'], $category_ids_by_wordset[(string) $fixture['wordset_one_id']] ?? []);
        $this->assertSame(
            'Select a word set to edit categories.',
            (string) (($config['strings'] ?? [])['selectWordsetNotice'] ?? '')
        );
    }

    /**
     * @return array<string,int>
     */
    private function createScopedCategoryFixture(): array
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset_one_id = $this->ensureTerm('wordset', 'Quick Edit Scope One', 'quick-edit-scope-one');
        $wordset_two_id = $this->ensureTerm('wordset', 'Quick Edit Scope Two', 'quick-edit-scope-two');
        $source_category_id = $this->ensureTerm('word-category', 'Shared Quick Edit Trees', 'shared-quick-edit-trees');

        $isolated_one_id = (int) ll_tools_get_or_create_isolated_category_copy($source_category_id, $wordset_one_id);
        $isolated_two_id = (int) ll_tools_get_or_create_isolated_category_copy($source_category_id, $wordset_two_id);

        return [
            'wordset_one_id' => $wordset_one_id,
            'wordset_two_id' => $wordset_two_id,
            'source_category_id' => $source_category_id,
            'isolated_one_id' => $isolated_one_id,
            'isolated_two_id' => $isolated_two_id,
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

    /**
     * @return array<string,mixed>
     */
    private function extractLocalizedConfig(string $localized, string $variableName): array
    {
        $matches = [];
        preg_match('/var ' . preg_quote($variableName, '/') . ' = (\{.*?\});/s', $localized, $matches);
        $this->assertArrayHasKey(1, $matches);

        $decoded = json_decode((string) $matches[1], true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
