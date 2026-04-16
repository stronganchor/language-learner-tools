<?php
declare(strict_types=1);

final class FullExportCategoryScopeTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $getBackup = [];

    /** @var mixed */
    private $originalIsolationOption;

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        $this->originalIsolationOption = get_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, null);
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;

        if ($this->originalIsolationOption === null) {
            delete_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION);
        } else {
            update_option(
                LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION,
                $this->originalIsolationOption,
                false
            );
        }

        parent::tearDown();
    }

    public function test_localized_full_export_categories_are_scoped_by_wordset(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createIsolatedWordsetFixture();

        ll_tools_enqueue_export_import_assets('tools_page_' . ll_tools_get_export_page_slug());

        $localized = wp_scripts()->get_data('ll-tools-export-import-admin-js', 'data');
        $this->assertIsString($localized);

        $config = $this->extractLocalizedConfig($localized);
        $this->assertIsArray($config);

        $this->assertArrayNotHasKey('fullExportCategoriesByWordset', $config);
        $this->assertSame('ll_tools_export_full_bundle_categories', (string) ($config['fullExportCategoriesAjaxAction'] ?? ''));
        $this->assertNotSame('', (string) ($config['fullExportCategoriesNonce'] ?? ''));
        $this->assertSame('Select a word set first', (string) ($config['fullExportCategoriesPrompt'] ?? ''));
        $this->assertSame('Loading categories for the selected word set...', (string) ($config['fullExportCategoriesLoading'] ?? ''));
        $this->assertSame('Could not load categories for this word set. Reload the page and try again.', (string) ($config['fullExportCategoriesRequestFailed'] ?? ''));
    }

    public function test_export_page_full_export_categories_follow_selected_wordset(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createIsolatedWordsetFixture();
        $_GET['full_export_wordset_id'] = (string) $fixture['wordset_one_id'];

        ob_start();
        ll_tools_render_export_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('id="ll_full_export_wordset_id"', $output);

        $select_markup = $this->extractSelectMarkup($output, 'll_full_export_category_ids');
        $this->assertStringContainsString('Shared Trees', $select_markup);
        $this->assertStringNotContainsString('Wordset Two Only', $select_markup);
    }

    public function test_ajax_full_export_categories_returns_rows_for_requested_wordset(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createIsolatedWordsetFixture();

        $_POST = [
            '_wpnonce' => wp_create_nonce('ll_tools_export_full_bundle_categories'),
            'wordset_id' => (string) $fixture['wordset_two_id'],
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_tools_ajax_export_full_bundle_categories();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($response['success']);
        $this->assertSame((string) $fixture['wordset_two_id'], (string) ($response['data']['wordsetId'] ?? ''));
        $rows = $response['data']['rows'] ?? null;
        $this->assertIsArray($rows);
        $this->assertSame(['Shared Trees', 'Wordset Two Only'], $this->extractRowLabels($rows));
    }

    /**
     * @return array{wordset_one_id:int,wordset_two_id:int}
     */
    private function createIsolatedWordsetFixture(): array
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset_one_id = $this->ensureTerm('wordset', 'Full Export Scope One', 'full-export-scope-one');
        $wordset_two_id = $this->ensureTerm('wordset', 'Full Export Scope Two', 'full-export-scope-two');
        $shared_category_id = $this->ensureTerm('word-category', 'Shared Trees', 'full-export-shared-trees');
        $wordset_two_only_category_id = $this->ensureTerm('word-category', 'Wordset Two Only', 'full-export-wordset-two-only');

        $this->createWordInScope('Scope One Tree', $wordset_one_id, $shared_category_id);
        $this->createWordInScope('Scope Two Tree', $wordset_two_id, $shared_category_id);
        $this->createWordInScope('Scope Two Only Word', $wordset_two_id, $wordset_two_only_category_id);

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        ll_tools_run_wordset_isolation_migration();

        return [
            'wordset_one_id' => $wordset_one_id,
            'wordset_two_id' => $wordset_two_id,
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
            'post_type'   => 'words',
            'post_status' => 'publish',
            'post_title'  => $title,
        ]);

        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($word_id, [$category_id], 'word-category', false);

        return (int) $word_id;
    }

    /**
     * @return array<string,mixed>
     */
    private function extractLocalizedConfig(string $localized): array
    {
        $matches = [];
        preg_match_all('/var llToolsImportUi = (\{.*?\});/s', $localized, $matches);
        $this->assertNotEmpty($matches[1] ?? []);

        $json = (string) end($matches[1]);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function extractSelectMarkup(string $html, string $select_id): string
    {
        $matches = [];
        preg_match('/<select[^>]+id="' . preg_quote($select_id, '/') . '"[^>]*>(.*?)<\/select>/s', $html, $matches);
        $this->assertArrayHasKey(1, $matches);

        return (string) $matches[1];
    }

    /**
     * @return array<string, mixed>
     */
    private function run_json_endpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $die_ajax_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_ajax_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_ajax_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,string>
     */
    private function extractRowLabels(array $rows): array
    {
        return array_values(array_filter(array_map(static function ($row): string {
            return is_array($row) && isset($row['label']) ? (string) $row['label'] : '';
        }, $rows)));
    }
}
