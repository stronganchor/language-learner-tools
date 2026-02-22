<?php
declare(strict_types=1);

final class AdminImportHandlersFlowTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $serverBackup = [];

    /** @var array<string,mixed> */
    private $getBackup = [];

    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $requestBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        parent::tearDown();
    }

    public function test_admin_handlers_preview_import_and_undo_minimal_bundle_from_server_zip(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is not available in this test environment.');
        }

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        update_option(ll_tools_import_history_option_name(), [], false);
        delete_transient('ll_tools_import_result');

        $bundle = $this->createMinimalServerImportBundleZip();
        $zipPath = (string) ($bundle['zip_path'] ?? '');
        $zipFilename = (string) ($bundle['zip_filename'] ?? '');
        $categorySlug = (string) ($bundle['category_slug'] ?? '');
        $this->assertNotSame('', $zipPath);
        $this->assertNotSame('', $zipFilename);
        $this->assertNotSame('', $categorySlug);
        $this->assertFileExists($zipPath);

        try {
            $previewRedirect = $this->captureRedirect(function () use ($zipFilename): void {
                $_SERVER['REQUEST_METHOD'] = 'POST';
                $_GET = [];
                $_POST = [
                    '_wpnonce' => wp_create_nonce('ll_tools_preview_import_bundle'),
                    'action' => 'll_tools_preview_import_bundle',
                    'll_import_existing' => $zipFilename,
                ];
                $_REQUEST = $_POST;
                ll_tools_handle_preview_import_bundle();
            });

            $previewQuery = $this->parseRedirectQuery($previewRedirect);
            $previewToken = (string) ($previewQuery['ll_import_preview'] ?? '');
            $this->assertNotSame('', $previewToken, 'Preview redirect should include ll_import_preview token.');

            $previewTransient = get_transient(ll_tools_import_preview_transient_key($previewToken));
            $this->assertIsArray($previewTransient);
            $this->assertSame($zipFilename, (string) ($previewTransient['zip_name'] ?? ''));
            $this->assertSame('server', (string) ($previewTransient['source_type'] ?? ''));

            $importRedirect = $this->captureRedirect(function () use ($previewToken): void {
                $_SERVER['REQUEST_METHOD'] = 'POST';
                $_GET = [];
                $_POST = [
                    '_wpnonce' => wp_create_nonce('ll_tools_import_bundle'),
                    'action' => 'll_tools_import_bundle',
                    'll_import_preview_token' => $previewToken,
                ];
                $_REQUEST = $_POST;
                ll_tools_handle_import_bundle();
            });

            $this->assertNotSame('', $importRedirect);
            $importResult = get_transient('ll_tools_import_result');
            $this->assertIsArray($importResult);
            $this->assertTrue((bool) ($importResult['ok'] ?? false), implode(' | ', (array) ($importResult['errors'] ?? [])));
            $this->assertFalse(get_transient(ll_tools_import_preview_transient_key($previewToken)));

            $importedCategory = get_term_by('slug', $categorySlug, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $importedCategory);
            $importedCategoryId = (int) $importedCategory->term_id;
            $this->assertGreaterThan(0, $importedCategoryId);

            $history = ll_tools_import_read_history();
            $this->assertNotEmpty($history);
            $entry = is_array($history[0] ?? null) ? $history[0] : [];
            $entryId = (string) ($entry['id'] ?? '');
            $this->assertNotSame('', $entryId);
            $this->assertContains($importedCategoryId, (array) (($entry['undo'] ?? [])['category_term_ids'] ?? []));

            $undoRedirect = $this->captureRedirect(function () use ($entryId): void {
                $_SERVER['REQUEST_METHOD'] = 'POST';
                $_GET = [];
                $_POST = [
                    'action' => 'll_tools_undo_import',
                    'll_tools_undo_import_nonce' => wp_create_nonce('ll_tools_undo_import'),
                    'll_import_history_id' => $entryId,
                ];
                $_REQUEST = $_POST;
                ll_tools_handle_undo_import();
            });

            $this->assertNotSame('', $undoRedirect);
            $undoResult = get_transient('ll_tools_import_result');
            $this->assertIsArray($undoResult);
            $this->assertTrue((bool) ($undoResult['ok'] ?? false), implode(' | ', (array) ($undoResult['errors'] ?? [])));
            $this->assertEmpty(term_exists($importedCategoryId, 'word-category'));

            $historyAfterUndo = ll_tools_import_read_history();
            $updatedEntry = is_array($historyAfterUndo[0] ?? null) ? $historyAfterUndo[0] : [];
            $this->assertGreaterThan(0, (int) ($updatedEntry['undone_at'] ?? 0));
            $this->assertIsArray($updatedEntry['undo_result'] ?? null);
        } finally {
            @unlink($zipPath);
            delete_transient('ll_tools_import_result');
        }
    }

    /**
     * @return array{zip_path:string,zip_filename:string,category_slug:string}
     */
    private function createMinimalServerImportBundleZip(): array
    {
        $importDir = ll_tools_get_import_dir();
        $this->assertTrue(ll_tools_ensure_import_dir($importDir));

        $suffix = wp_generate_password(8, false, false);
        $categorySlug = 'import-handler-cat-' . strtolower($suffix);
        $payload = [
            'bundle_type' => 'images',
            'categories' => [
                [
                    'slug' => $categorySlug,
                    'name' => 'Import Handler Category ' . $suffix,
                    'description' => 'Preview/import/undo handler flow test',
                    'parent_slug' => '',
                    'meta' => [
                        'display_color' => ['blue'],
                        '_wp_note' => ['should-be-blocked'],
                    ],
                ],
            ],
            'word_images' => [],
            'wordsets' => [],
            'words' => [],
            'media_estimate' => [
                'attachment_count' => 0,
                'attachment_bytes' => 0,
            ],
        ];

        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);

        $zipFilename = 'll-tools-admin-import-handler-' . strtolower($suffix) . '.zip';
        $zipPath = trailingslashit($importDir) . $zipFilename;
        @unlink($zipPath);

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($opened === true, 'Failed to create test import zip.');
        $this->assertTrue($zip->addFromString('data.json', $json));
        $this->assertTrue($zip->close());
        $this->assertFileExists($zipPath);

        return [
            'zip_path' => wp_normalize_path($zipPath),
            'zip_filename' => $zipFilename,
            'category_slug' => $categorySlug,
        ];
    }

    private function captureRedirect(callable $callback): string
    {
        $redirectUrl = '';
        $redirectFilter = static function ($location) use (&$redirectUrl) {
            $redirectUrl = (string) $location;
            throw new RuntimeException('redirect_intercepted');
        };
        add_filter('wp_redirect', $redirectFilter, 10, 1);

        try {
            $callback();
            $this->fail('Expected redirect.');
        } catch (RuntimeException $e) {
            $this->assertSame('redirect_intercepted', $e->getMessage());
        } finally {
            remove_filter('wp_redirect', $redirectFilter, 10);
        }

        $this->assertNotSame('', $redirectUrl);
        return $redirectUrl;
    }

    /**
     * @return array<string,string>
     */
    private function parseRedirectQuery(string $url): array
    {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        $decoded = [];
        parse_str($query, $decoded);
        return array_map('strval', $decoded);
    }
}
